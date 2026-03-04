<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

namespace Payneteasy\lib;

defined('PAYNETEASY_LIB') or die('Restricted access');

class ApiException extends \Exception {
	private array $context = [];

	public function __construct(string $message, array $in_out = [], int $code = 0, \Throwable $previous = null) {
		parent::__construct($message, $code, $previous);

		error_log($this->message.' in '.$this->file.':'.$this->line);

		if ($in = array_shift($in_out)) {
			ksort($in);
			foreach ($in as $key => $value)
				error_log(" -> '$key' => '$value'");

			if ($out = array_shift($in_out)) {
				error_log('');

				ksort($out);
				foreach ($out as $key => $value)
					error_log(" <- '$key' => '$value'");
			}
		}
	}
}

?>
