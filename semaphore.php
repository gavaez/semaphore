<?php

class IPCException extends Exception {}


abstract class IPCResource {
	/**
	 * @var bool
	 * @access public
	 */
	public $locked = false;
	/**
	 * @var resource
	 * @access protected
	 */
	protected $handler = null;
	/**
	 * @var string
	 * @access private
	 */
	private $_filename = '';
	/**
	 * @var int
	 * @access private
	 */
	private $_ipc = -1;

	/**
	 * @constructor
	 * @param mixed[] $params
	 * @access public
	 * @throws IPCException
	 */
	public function __construct(array $params = []) {
		$this->_filename = tempnam('/tmp', mb_strtolower(get_class($this)));
		$this->_ipc = ftok($this->_filename, 's');
		$this->handler = $this->create($params);

		if (empty($this->handler)) {
			throw new IPCException('System V IPC resource creating error');
		}
	}

	/**
	 * @destructor
	 * @access public
	 */
	public function __destruct() {
		if (defined('DEBUG_MODE') && DEBUG_MODE) {
			echo get_class($this), '::', __FUNCTION__, "\n";
		}
		if ($this->free()) {
			if (defined('DEBUG_MODE') && DEBUG_MODE) {
				echo "\tRemove ", get_class($this), " object\n";
			}
			unlink($this->getTmpFilename());
		}
	}

	/**
	 * @return resource
	 * @access public
	 */
	public function getHandler() {
		return $this->handler;
	}

	/**
	 * @return bool
	 * @access public
	 */
	public function isValid() {
		return file_exists($this->getTmpFilename());
	}

	/**
	 * @abstract
	 * @param mixed[] $params
	 * @return int
	 * @access protected
	 */
	abstract protected function create(array $params = []);

	/**
	 * @return bool
	 * @access protected
	 */
	protected function free() {
		return !$this->locked && $this->isValid();
	}

	/**
	 * @return string
	 * @access protected
	 */
	protected function getTmpFilename() {
		return $this->_filename;
	}

	/**
	 * @return int
	 * @access protected
	 */
	protected function getIPCKey() {
		return $this->_ipc;
	}
}


class Semaphore extends IPCResource {
	/**
	 * занять ресурс
	 *
	 * @return bool
	 * @access public
	 */
	public function acquire() {
		return $this->isValid() && sem_acquire($this->getHandler());
	}

	/**
	 * освободить ресурс
	 *
	 * @return bool
	 * @access public
	 */
	public function release() {
		return $this->isValid() && sem_release($this->getHandler());
	}

	/**
	 * @param mixed[] $params
	 * @return int
	 * @access protected
	 */
	protected function create(array $params = []) {
		return sem_get($this->getIPCKey());
	}

	/**
	 * @return bool
	 * @access protected
	 */
	protected function free() {
		return parent::free() && sem_remove($this->getHandler());
	}
}


class ShmBlock extends IPCResource {
	const CREATE = 'n';
	const EXISTING = 'a';
	const RESERVE = 'c';
	const REWRITABLE = 'w';

	/**
	 * @constructor
	 * @param int $size
	 * @param string $mode
	 * @param int $rights
	 * @access public
	 */
	public function __construct($size, $mode = self::RESERVE, $rights = 0666) {
		parent::__construct([$mode, $rights, $size]);
	}

	/**
	 * @return int
	 * @access public
	 */
	public function getSize() {
		return shmop_size($this->getHandler());
	}

	/**
	 * @return string
	 * @access public
	 */
	public function read() {
		$data = $this->isValid() ? shmop_read($this->getHandler(), 0, $this->getSize()) : '';
		$l = strlen($data);
		$i = 0;
		while (($i < $l) && ord($data[$i])) {
			++$i;
		}
		return substr($data, 0, $i);
	}

	/**
	 * @param string $data
	 * @return int|bool
	 * @access public
	 */
	public function write($data) {
		return $this->isValid() ? shmop_write($this->getHandler(), $data, 0) : false;
	}

	/**
	 * @param mixed[] $params
	 * @return int
	 * @access protected
	 */
	protected function create(array $params = []) {
		array_unshift($params, $this->getIPCKey());
		return call_user_func_array('shmop_open', $params);
	}

	/**
	 * @return bool
	 * @access protected
	 */
	protected function free() {
		if (parent::free() && shmop_delete($h = $this->getHandler())) {
			shmop_close($h);
			return true;
		}
		return false;
	}
}


class SharedMemory {
	const SHMALL = 'shmall'; // максимальный размер разделяемой памяти
	const SHMMAX = 'shmmax'; // максимальный размер сегмента
	const SHMMNI = 'shmmni'; // максимальное кол-во сегментов

	/**
	 * @var ShmBlock[]
	 * @access protected
	 */
	protected $blocks = [];

	/**
	 * @constructor
	 * @param int $size
	 * @access public
	 */
	public function __construct($size = 0) {
		$this->realloc($size);
	}

	/**
	 * @param int $size
	 * @return void
	 * @access public
	 * @throws IPCException
	 */
	public function realloc($size = 0) {
		if (!$size) {
			$size = intval(ini_get('sysvshm.init_mem'));
		}
		$blockSize = min($size, self::getSystemValue(self::SHMMAX));
		if (empty($blockSize)) {
			throw new IPCException('Shared memory block size is empty');
		}

		$count = intval(ceil($size / $blockSize));
		$maxCount = self::getSystemValue(self::SHMMNI);
		if ($maxCount < $count) {
			throw new IPCException(sprintf('System memory segment count is too small - %u, %u needed', $maxCount, $count));
		}

		$size = $count * $blockSize;
		$totalSize = self::getSystemValue(self::SHMALL);
		if ($totalSize < $size) {
			throw new IPCException(sprintf(
				'System shared memory size is too small - %sB, %sB needed',
				number_format($totalSize),
				number_format($size)
			));
		}

		$this->free();
		for ($i = 0; $i < $count; ++$i) {
			$this->blocks[] = new ShmBlock($blockSize);
		}
		$this->setData([]);
	}

	/**
	 * @return bool
	 * @access public
	 */
	public function free() {
		$this->lock(false);
		$this->blocks = [];
	}

	/**
	 * @param bool $on
	 * @access public
	 */
	public function lock($on = true) {
		array_walk($this->blocks, function(ShmBlock $block) use ($on) {$block->locked = $on;});
	}

	/**
	 * @param array $data
	 * @return int|bool
	 * @access public
	 */
	public function setData(array $data) {
		$value = serialize($data);

		/** @var $block ShmBlock */
		foreach ($this->blocks as $block) {
			$size = $block->getSize();
			if ($block->write($value ? substr($value, 0, $size) : '') === false) {
				return false;
			}
			$value = substr($value, $size);
		}

		return true;
	}

	/**
	 * @return array
	 * @access public
	 * @throws IPCException
	 */
	public function getData() {
		$data = '';

		foreach ($this->blocks as $block) {
			$value = $block->read();
			if ($value === false) {
				throw new IPCException('Shared memory read error');
			}
			$data .= $value;
		}

		return $data ? unserialize($data) : [];
	}

	/**
	 * @static
	 * @param string $param
	 * @return int
	 * @access protected
	 */
	protected static function getSystemValue($param) {
		return intval(shell_exec("cat /proc/sys/kernel/$param"));
	}
}


/**
 * @property-read Semaphore $semaphoreInstance
 * @property-read SharedMemory $memoryInstance
 */
class Mutex {
	/**
	 * @var Semaphore
	 * @access protected
	 */
	protected $semaphore = null;
	/**
	 * @var SharedMemory
	 * @access protected
	 */
	protected $memory = null;

	/**
	 * @constructor
	 * @param int $memsize
	 * @access public
	 */
	public function __construct($memsize = 0) {
		$this->semaphore = new Semaphore();
		$this->memory = new SharedMemory($memsize);
	}

	/**
	 * @destructor
	 * @access public
	 */
	public function __destruct() {
		if (defined('DEBUG_MODE') && DEBUG_MODE) {
			echo __METHOD__, "\n";
		}

	// нужно отметить, что завершаемый процесс больше не будет обращаться к общей памяти
		if ($this->semaphore->acquire()) {
			$data = $this->memory->getData();
			foreach ($data as $varname => &$value) {
				$key = array_search(posix_getpid(), $value['pids']);
				if ($key !== false) {
					array_splice($value['pids'], $key, 1);
				}
				if (empty($value['pids'])) {
					unset($data[$varname]);
				}
			}
			if (!empty($data)) {
				$this->memory->setData($data);
			}
			$this->semaphore->release();
		}

	// если все процессы завершились, то можно очистить память
		$this->lock(!empty($data));
	}

	/**
	 * записать значение
	 *
	 * @param string $varname
	 * @param mixed $value
	 * @return bool
	 * @access public
	 */
	public function set($varname, $value) {
		if ($success = $this->semaphore->acquire()) {
			if (preg_match('#^\s*(\w+)\s*\[\s*(.*?)\s*\]\s*$#', $varname, $matches)) {
				list(, $varname, $key) = $matches;
			}

			$data = $this->memory->getData();
			if (!array_key_exists($varname, $data)) {
				$data[$varname] = ['value' => null, 'pids' => []];
			}

			if (isset($key)) {
				if (!is_array($data[$varname]['value'])) {
					$data[$varname]['value'] = [];
				}
				if ($key) {
					self::_setArrayItemValue($data[$varname]['value'], $key, $value);
				} elseif ($value !== null) {
					$data[$varname]['value'][] = $value;
				}
			} elseif ($value === null) {
				unset($data[$varname]);
			} else {
				$data[$varname]['value'] = $value;
			}

			if (!in_array($pid = posix_getpid(), $data[$varname]['pids'])) {
				$data[$varname]['pids'][] = $pid;
			}

			$success &= $this->memory->setData($data);
			$this->semaphore->release();
		}

		return $success;
	}

	/**
	 * получить значение
	 *
	 * @param string $varname
	 * @return mixed
	 * @access public
	 */
	public function get($varname) {
		if ($this->semaphore->acquire()) {
			if (preg_match('#^\s*(\w+)\s*\[\s*(.+?)\s*\]\s*$#', $varname, $matches)) {
				list(, $varname, $key) = $matches;
			}

			$data = $this->memory->getData();

			if (array_key_exists($varname, $data)) {
				if (isset($key)) {
					if (is_array($data[$varname]['value']) && array_key_exists($key, $data[$varname]['value'])) {
						$value = $data[$varname]['value'][$key];
					}
				} else {
					$value = $data[$varname]['value'];
				}

				if (isset($value) && !in_array($pid = posix_getpid(), $data[$varname]['pids'])) {
					$data[$varname]['pids'][] = $pid;
					$this->memory->setData($data);
				}
			}

			$this->semaphore->release();
		}

		return isset($value) ? $value : null;
	}

	/**
	 * изменить значение
	 *
	 * @param string $varname
	 * @param mixed $setter
	 * @return mixed
	 * @access public
	 */
	public function modify($varname, $setter) {
		if ($this->semaphore->acquire()) {
			if (preg_match('#^\s*(\w+)\s*\[\s*(.*?)\s*\]\s*$#', $varname, $matches)) {
				list(, $varname, $key) = $matches;
			}

			$data = $this->memory->getData();

			if (is_array($data) && array_key_exists($varname, $data)) {
				if (isset($key)) {
					if (is_array($data[$varname]['value'])) {
						$value = array_key_exists($key, $data[$varname]['value']) ? $data[$varname]['value'][$key] : false;
					}
				} else {
					$value = $data[$varname]['value'];
				}

				if (isset($value)) {
					if (is_callable($setter)) {
						$setter = call_user_func($setter, $value);
					}

					if (isset($key)) {
						self::_setArrayItemValue($data[$varname]['value'], $key, $setter);
					} elseif ($setter === null) {
						unset($data[$varname]);
					} else {
						$data[$varname]['value'] = $setter;
					}

					if (!in_array($pid = posix_getpid(), $data[$varname]['pids'])) {
						$data[$varname]['pids'][] = $pid;
					}

					if (!$this->memory->setData($data)) {
						unset($value);
					}
				}
			}

			$this->semaphore->release();
		}

		return isset($value) ? $value : null;
	}

	/**
	 * очистить значение
	 *
	 * @param string $varname
	 * @return bool
	 * @access public
	 */
	public function clear($varname) {
		return $this->set($varname, null);
	}

	/**
	 * @param bool $on
	 * @return void
	 * @access public
	 */
	public function lock($on = true) {
		$this->memory->lock($this->semaphore->locked = $on);
	}

	/**
	 * @param string $name
	 * @return mixed
	 * @throws RuntimeException
	 */
	public function __get($name) {
		if (preg_match('#^(semaphore|memory)Instance$#i', $name, $matches)) {
			return $this->{mb_strtolower($matches[1])};
		}
		throw new RuntimeException("Unknown property $name");
	}

	/**
	 * @static
	 * @param array &$values
	 * @param mixed $key
	 * @param mixed $value
	 * @return void
	 * @access private
	 */
	private static function _setArrayItemValue(array &$values, $key, $value) {
		if ($value === null) {
			unset($values[$key]);
		} else {
			$values[$key] = $value;
		}
	}
}