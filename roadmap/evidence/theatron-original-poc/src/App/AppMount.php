<?php

declare(strict_types=1);

namespace Phalanx\Theatron\App;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Store\Lens;

final class AppMount
{
    private(set) MountedComponent $root;
    private(set) FocusManager $focus;
    private EventParser $parser;

    public function __construct(
        StatefulComponent $component,
        ?ExecutionScope $scope = null,
        ?Lens $lens = null,
        string $focusName = 'root',
    ) {
        $this->root = new MountedComponent($component, $scope, $lens);
        $this->focus = new FocusManager();
        $this->parser = new EventParser();

        $this->root->render();
        $this->focus->register($focusName, $this->root);
        $this->focus->focus($focusName);
    }

    public function injectInput(string $text): void
    {
        foreach ($this->parser->parse($text) as $event) {
            $this->focus->dispatch($event);
        }
    }

    public function wireInput(Stage $stage): void
    {
        $focus = $this->focus;
        $root = $this->root;

        $stage->onInput(static function (InputEvent $event) use ($focus, $root, $stage): void {
            if (!$focus->dispatch($event)) {
                return;
            }

            if ($root->isDirty) {
                $stage->requestFrame();
            }
        });
    }

    public function dispose(): void
    {
        $this->root->dispose();
    }
}
