<?php
declare(strict_types=1);

/**
 * A held, non-blocking exclusive lock shared with the worker: an flock() on a
 * file beside the SQLite database, or a PostgreSQL session advisory lock on a
 * dedicated connection. Either is released if the holder dies.
 */
final class SemanticGrouping_SemanticLock {
	/** @param resource|null $file */
	private function __construct(
		private mixed $file,
		private ?PDO $connection,
		private readonly int $key,
	) {}

	/** @param resource $file */
	public static function file($file): self {
		return new self($file, null, 0);
	}

	public static function advisory(PDO $connection, int $key): self {
		return new self(null, $connection, $key);
	}

	public function release(): void {
		if (is_resource($this->file)) {
			flock($this->file, LOCK_UN);
			fclose($this->file);
		}
		$this->file = null;
		if ($this->connection !== null) {
			try {
				$this->connection->prepare('SELECT pg_advisory_unlock(?)')->execute([$this->key]);
			} catch (Throwable) {
				// Closing the session below releases the lock anyway.
			}
		}
		$this->connection = null;
	}

	public function __destruct() {
		$this->release();
	}
}
