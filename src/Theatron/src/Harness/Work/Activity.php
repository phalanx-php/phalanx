<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Work;

enum Activity: string
{
    case Thinking = 'thinking';
    case Exploring = 'exploring';
    case Researching = 'researching';
    case Editing = 'editing';
    case Testing = 'testing';
    case Reviewing = 'reviewing';
}
