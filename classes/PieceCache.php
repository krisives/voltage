<?php

class PieceCache {
  protected $torrent;
  protected $dir;
  protected $openFiles;
  protected $pieces;
  protected $pieceCount;
  protected $pieceSize;
  protected $checked;
  protected $dirty;
  protected $maxPieces;
  protected $pieceSequences;

  public function __construct(Torrent $torrent, $dir) {
    $this->torrent = $torrent;
    $this->dir = $dir;
    $this->pieceSize = $torrent->getPieceSize();
    $this->pieceCount = $torrent->getPieceCount();
    $this->maxPieces = self::calculateMaxPieces($this->pieceSize);
    $this->openFiles = [];
    $this->pieces = [];
    $this->dirty = [];
    $this->pieceSequences = array_fill(0, $this->pieceCount, 0);
  }

  public function getCompletedPieceCount() {
    return count($this->checked);
  }

  public function getRandomUnfinishedPiece() {
    $unfinished = array_values(
      array_diff(
        range(0, $this->pieceCount - 1),
        array_keys($this->checked)
      )
    );

    $i = rand(0, count($unfinished) - 1);
    return $unfinished[$i];
  }

  public function getPieceSequence($i) {
    $this->assertPieceIndex($i);
    $x = $this->pieceSequences[$i];
    $this->pieceSequences[$i]++;
    return $x;
  }

  public function hasPiece($i) {
    $this->assertPieceIndex($i);
    return !empty($this->checked[$i]);
  }

  public function getBitfield() {
    $str = '';
    $byte = 0;
    $j = 0;

    for ($i=0; $i < $this->pieceCount; $i++) {
      $byte |= (1 << $j);
      $j++;

      if ($j >= 8) {
        $j = 0;
        $byte = 0;
        $str .= chr($byte);
      }
    }

    if ($j > 0) {
      $str .= chr($byte);
    }

    return $str;
  }

  /** Flush to disk and discard any incomplete download data and close all
    * file handles */
  public function close() {
    $this->flush(true);

    foreach ($this->openFiles as $index => $file) {
      fclose($file);
    }

    $this->openFiles = [];
    $this->pieces = [];
    $this->dirty = [];
  }

  /** Check if a piece index is within bounds */
  public function assertPieceIndex($i) {
    if ($i < 0 || $i >= $this->pieceCount) {
      throw new Exception("Piece index out of bounds");
    }
  }

  public function getProgress() {
    $done = (float)count($this->checked);
    $total = (float)$this->pieceCount;
    return sprintf('%0.2f', ($done / $total) * 100.0);
  }

  public function isComplete() {
    return count($this->checked) === $this->pieceCount;
  }

  /** Ensure all files are allocated (padding with zeros) */
  public function allocate($emitter=null) {
    if ($emitter) {
      $emitter->emit('allocate-start');
    }

    $totalSize = $this->torrent->getTotalSize();
    $allocatedBytes = 0;
    $allocatedPieces = [];

    foreach ($this->torrent->getFiles() as $file) {
      $size = $this->torrent->getFileSize($file);
      $allocatedBytes += $size;

      $this->allocateFile($file, $size, $allocatedPieces, $emitter);

      if ($emitter) {
        $emitter->emit('allocate', $allocatedBytes, $totalSize);
      }
    }

    if ($emitter) {
      $emitter->emit('allocate-end');
    }

    return $allocatedPieces;
  }

  protected function allocateFile($file, $size, &$allocatedPieces, $emitter=null) {
    $path = "{$this->dir}/$file";

    if (file_exists($path)) {
      $existingSize = filesize($path);
    } else {
      $existingSize = 0;
    }

    $fp = $this->openFile($file);

    if (!$fp) {
      throw new Exception("Cannot write file '$file'");
    }

    $pieceSize = $this->torrent->getPieceSize();
    $emptyData = str_repeat(chr(0), $pieceSize);
    $remaining = $size - $existingSize;

    if (fseek($fp, $existingSize, SEEK_SET) !== 0) {
      throw new Exception("Unable to seek within file '$file'");
    }

    $offset = $existingSize;

    while ($remaining > 0) {
      $writeSize = min($pieceSize, $remaining);
      $bytesWritten = fwrite($fp, $emptyData, $writeSize);

      if ($bytesWritten === false || $bytesWritten < 0) {
        throw new Exception("Unable to allocate '$file'");
      }

      $remaining -= $bytesWritten;

      $startPiece = (int)floor($offset / $pieceSize);
      $endPiece = (int)ceil(($offset + $bytesWritten) / $pieceSize);

      for ($i=$startPiece; $i < $endPiece; $i++) {
        $allocatedPieces[$i] = $i;
      }

      $offset += $bytesWritten;
    }
  }

  /** Check existing piece data on disk. Optionally provide an array of
    * pieces to skip (since they are known to be incomplete) */
  public function check($skip=null, $emitter=null) {
    $this->checked = [];
    $pieceCount = $this->torrent->getPieceCount();

    if (empty($skip)) {
      $skip = [];
    }

    if ($emitter) {
      $emitter->emit('check-start');
    }

    for ($i=0; $i < $pieceCount; $i++) {
      if (isset($skip[$i]) && ($skip[$i] === $i)) {
        continue;
      }

      $hash = sha1($this->readPiece($i), true);
      $pieceHash = $this->torrent->getPieceHash($i);

      if ($hash === $pieceHash) {
        $this->checked[$i] = 1;
      }

      if ($emitter) {
        $emitter->emit('check', $i, $pieceCount);
      }
    }

    if ($emitter) {
      $emitter->emit('check-end');
    }
  }

  public function flush($force=false) {
    if ($force) {
      $this->flushAll();
      return;
    }

    $this->flushDirty();
  }

  protected function flushAll() {
    if (empty($this->pieces)) {
      return;
    }

    ksort($this->pieces, SORT_NUMERIC);

    foreach (array_keys($this->pieces) as $pieceIndex) {
      $this->writePiece($pieceIndex, true);
    }
  }

  protected function flushDirty() {
    if (empty($this->dirty)) {
      return;
    }

    // Sorting the pending writes means we can write them in order
    // instead of seeking around disk
    ksort($this->dirty, SORT_NUMERIC);

    foreach ($this->dirty as $pieceIndex => $lastModified) {
      unset($this->dirty[$pieceIndex]);
      $this->writePiece($pieceIndex);
    }
  }

  public function writePiece($pieceIndex, $force=false) {
    $this->assertPieceIndex($pieceIndex);

    $hash = sha1($this->pieces[$pieceIndex], true);
    $pieceHash = $this->torrent->getPieceHash($pieceIndex);
    $verified = ($hash === $pieceHash);

    if (!$verified && !$force) {
      return;
    }

    //echo "[DEBUG] Writing piece #$pieceIndex\n";
    $files = $this->torrent->getPieceFiles($pieceIndex);
    $pieceOffset = $pieceIndex * $this->pieceSize;

    foreach ($files as $file) {
      $fp = $this->openFile($file);
      $fileOffset = $this->torrent->getFileOffset($file);

      if ($pieceOffset >= $fileOffset) {
        $offset = $pieceOffset - $fileOffset;
      } else {
        $offset = 0;
      }

      if (fseek($fp, $offset, SEEK_SET) !== 0) {
        throw new Exception("Can't seek within file for piece");
      }

      $this->writeData($fp, $this->pieces[$pieceIndex]);
    }

    unset($this->pieces[$pieceIndex]);
    unset($this->dirty[$pieceIndex]);

    if ($verified) {
      $this->checked[$pieceIndex] = 1;
    }
  }

  protected function writeData($fp, $data) {
    $remaining = strlen($data);
    $offset = 0;

    while ($remaining > 0) {
      if ($offset === 0) {
        $bytesWritten = fwrite($fp, $data);
      } else {
        $bytesWritten = fwrite($fp, substr($data, $offset));
      }

      if ($bytesWritten === false || $bytesWritten < 0) {
        throw new Exception("Unable to write piece data");
      }

      $offset += $bytesWritten;
      $remaining -= $bytesWritten;
    }
  }

  protected function openFile($file) {
    if (!is_string($file) || strlen($file) <= 0) {
      throw new Exception("Must pass a file to open");
    }

    if (isset($this->openFiles[$file])) {
      return $this->openFiles[$file];
    }

    $path = "{$this->dir}/$file";
    $fp = fopen($path, 'c+b');

    if (empty($fp)) {
      throw new Exception("Unable to open file '$path'");
    }

    $this->openFiles[$file] = $fp;
    return $fp;
  }

  public function readPiece($pieceIndex) {
    $this->assertPieceIndex($pieceIndex);

    if (isset($this->pieces[$pieceIndex])) {
      return $this->pieces[$pieceIndex];
    }

    $files = $this->torrent->getPieceFiles($pieceIndex);
    $pieceOffset = ($pieceIndex * $this->pieceSize);
    $piece = '';
    $remaining = $this->pieceSize;

    foreach ($files as $file) {
      $fp = $this->openFile($file);
      $fileOffset = $this->torrent->getFileOffset($file);
      $fileSize = $this->torrent->getFileSize($file);

      if ($pieceOffset >= $fileOffset) {
        $pos = $pieceOffset - $fileOffset;
        $len = min($remaining, $fileSize - $pos);
      } else {
        $pos = 0;
        $len = min($remaining, $fileSize);
      }

      if (fseek($fp, $pos, SEEK_SET) !== 0) {
        throw new Exception("Unable to seek within file '$file'");
      }

      $chunk = $this->readData($fp, $len);
      $piece .= $chunk;
      $remaining -= strlen($chunk);
      $chunk = null;
    }

    return $piece;
  }

  protected function readData($fp, $remaining) {
    $data = '';

    while ($remaining > 0) {
      $chunk = fread($fp, $remaining);

      if ($chunk === false) {
        throw new Exception("Error reading file data");
      }

      $data .= $chunk;
      $remaining -= strlen($chunk);
      $chunk = null;
    }

    return $data;
  }

  public function commit($pieceIndex, $offset, $data) {
    $this->assertPieceIndex($pieceIndex);

    if ($offset < 0 || $offset >= $this->pieceSize) {
      throw new Exception("Attempt to write data outside (start) bounds");
    }

    $len = strlen($data);
    $end = $offset + $len;

    if ($end > $this->pieceSize) {
      throw new Exception("Attempt to write data outside (end) bounds");
    }

    if (count($this->pieces) >= $this->maxPieces) {
      $this->flush(true);
    }

    if (!array_key_exists($pieceIndex, $this->pieces)) {
      $this->pieces[$pieceIndex] = $this->readPiece($pieceIndex);
    }

    $this->pieces[$pieceIndex] = Util::updateString(
      $this->pieces[$pieceIndex],
      $offset,
      $data
    );

    $this->dirty[$pieceIndex] = 1;
  }

  /** Calculates how many pieces we should keep in memory */
  protected static function calculateMaxPieces($pieceSize) {
    $limit = self::getMemoryLimit();

    if ($limit <= 0) {
      // No memory limit
      return 1000;
    }

    // Current memory usage is a baseline
    $remaining = $limit - memory_get_usage();

    // Only use 2/3 memory so PHP always has some available
    $remaining = (int)($remaining * 0.6667);

    // Calculate piece count with respect to PHP bloat
    $count = (int)($remaining / ($pieceSize * 1.333));

    try {
        if ($count <= 0) {
            throw new Exception("Not enough memory for even a single piece ($limit)");
        }
    } catch (Exception $e) {
        echo 'Please increase PHP memory limit and try again';
    }

    if ($count < 3) {
      trigger_error("Memory is really low");
    }

    echo "[DEBUG] Max pieces is $count\n";
    return $count;
  }

  protected static function getMemoryLimit() {
    $limit = ini_get('memory_limit');
    $limit = str_replace(' ',  '', $limit);

    if (preg_match('#(\\d+)(K|M|G)#i', $limit, $match)) {
      $count = $match[1];
      $unit = $match[2];

      switch (strtoupper($unit)) {
        case 'K':
          $bytes = $count * 1024;
          break;
        case 'M':
          $bytes = $count * 1024 * 1024;
          break;
        case 'G':
          $bytes = $count * 1024 * 1024 * 1024;
          break;
        default:
          throw new Exception("Unknown memory unit '$unit'");
      }
    } else {
      $bytes = (int)$limit;
    }

    return $bytes;
  }
}
