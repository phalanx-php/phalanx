<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

enum Key: string
{
    case Enter = 'enter';
    case Tab = 'tab';
    case Backspace = 'backspace';
    case Delete = 'delete';
    case Escape = 'escape';
    case Space = 'space';

    case Up = 'up';
    case Down = 'down';
    case Left = 'left';
    case Right = 'right';

    case Home = 'home';
    case End = 'end';
    case PageUp = 'pageup';
    case PageDown = 'pagedown';
    case Insert = 'insert';

    case F1 = 'f1';
    case F2 = 'f2';
    case F3 = 'f3';
    case F4 = 'f4';
    case F5 = 'f5';
    case F6 = 'f6';
    case F7 = 'f7';
    case F8 = 'f8';
    case F9 = 'f9';
    case F10 = 'f10';
    case F11 = 'f11';
    case F12 = 'f12';
}
