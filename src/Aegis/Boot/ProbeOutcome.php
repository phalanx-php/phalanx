<?php

declare(strict_types=1);

namespace Phalanx\Boot;

enum ProbeOutcome
{
    /** Failed probe blocks boot — the app cannot run without this dependency. */
    case FailBoot;

    /** Failed probe is logged as a warning; the dependent feature reports unavailable at runtime. */
    case FeatureUnavailable;
}
