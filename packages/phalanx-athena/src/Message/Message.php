<?php

declare(strict_types=1);

namespace Phalanx\Athena\Message;

final class Message
{
	public string $text {
		get {
			$texts = [];
			foreach ($this->content as $block) {
				if ($block->kind === ContentKind::Text && $block->text !== null) {
					$texts[] = $block->text;
				}
			}
			return implode('', $texts);
		}
	}

    /** @param list<Content> $content */
    private function __construct(
        protected(set) Role $role,
        protected(set) array $content,
    ) {}

    public static function system(string $text): self
    {
        return new self(Role::System, [Content::text($text)]);
    }

    /** @param string|list<Content> $content */
    public static function user(string|array $content): self
    {
        if (is_string($content)) {
            return new self(Role::User, [Content::text($content)]);
        }

        return new self(Role::User, array_values($content));
    }

    /** @param string|list<Content> $content */
    public static function assistant(string|array $content): self
    {
        if (is_string($content)) {
            return new self(Role::Assistant, [Content::text($content)]);
        }

        return new self(Role::Assistant, array_values($content));
    }

    public static function toolResult(string $toolCallId, mixed $result): self
    {
        return new self(Role::User, [Content::toolResult($toolCallId, $result)]);
    }

    /** @return array{role: string, content: string|null|list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'content' => count($this->content) === 1 && $this->content[0]->kind === ContentKind::Text
                ? $this->content[0]->text
                : array_map(static fn(Content $c) => $c->toArray(), $this->content),
        ];
    }
}
