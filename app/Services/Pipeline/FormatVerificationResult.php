<?php

namespace App\Services\Pipeline;

class FormatVerificationResult
{
	/**
	 * @param array<int, array{code:string,status:string,message:string,details?:array<string,mixed>}> $checks
	 * @param array<int, string> $errors
	 * @param array<int, string> $warnings
	 * @param array<string, mixed> $stats
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $checks = [],
		public readonly array $errors = [],
		public readonly array $warnings = [],
		public readonly array $stats = [],
	) {
	}

	public function toArray(): array
	{
		return [
			'status' => $this->status,
			'checks' => $this->checks,
			'errors' => $this->errors,
			'warnings' => $this->warnings,
			'stats' => $this->stats,
		];
	}
}