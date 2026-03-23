<?php

declare(strict_types=1);

namespace NexusPM\mapping;

/**
 * Wraps NexusBlockTranslator as a RuntimeIdMapper for ChunkRewriter compatibility.
 */
class RuntimeIdMapperDelegate extends RuntimeIdMapper{

	private NexusBlockTranslator $translator;

	public function __construct(NexusBlockTranslator $translator){
		$this->translator = $translator;
		// Don't call parent constructor — we override all methods
	}

	public function toTarget(int $baseRuntimeId) : int{
		return $this->translator->nativeToTarget($baseRuntimeId);
	}

	public function toBase(int $targetRuntimeId) : int{
		return $this->translator->targetToNative($targetRuntimeId);
	}

	public function getMappedCount() : int{
		return $this->translator->getMappedCount();
	}

	public function getChangedCount() : int{
		return $this->translator->getChangedCount();
	}
}
