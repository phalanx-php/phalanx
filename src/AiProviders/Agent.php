<?php

declare(strict_types=1);

namespace Phalanx\AiProviders;

/**
 * The agent authoring contract - and why this lib is called 'ai-providers'.
 * An agent IS a bounded capability package — identity, declared capabilities,
 * context positioning, provider transport requirements, effect surface, and
 * output commitment. The model is the reasoning engine inside one invocation;
 * the agent is the package the runtime arms it with.
 *
 * Implementations declare each surface as a property hook returning an
 * immutable value object. No methods — implementing an agent is fielding
 * the eight property hooks.
 *
 * Example:
 *
 * ```php
 * class InvestigatorAgent implements Agent
 * {
 *     public string $id      { get => 'investigator'; }
 *     public string $name    { get => 'Investigator'; }
 *     public string $purpose { get => 'Preserve context, detect deltas, surface viable paths.'; }
 *
 *     public Capabilities $capabilities { get => Capabilities::of(
 *         Capability::Reasoning,
 *         Capability::ToolUse,
 *     ); }
 *
 *     public Context $context { get => Context::new()
 *         ->front(Mission::class)
 *         ->middle(SourceExcerpts::class)
 *         ->tail(OutputShape::class); }
 *
 *     public Provider\Needs $provider { get => Provider\Needs::new()
 *         ->prefer(Provider\Preference::LocalFirst)
 *         ->require(Capability::Reasoning); }
 *
 *     public Transport\Needs $transport { get => Transport\Needs::new()
 *         ->streaming()
 *         ->cancellable(); }
 *
 *     public Effects $effects { get => Effects::allow(
 *         Effect\Kind::FileRead,
 *         Effect\Kind::CodeSearch,
 *     )->requireApproval(
 *         Effect\Kind::FileWrite,
 *         Effect\Kind::ShellExec,
 *     ); }
 *
 *     public Output $output { get => Output::artifact(Artifact\Kind::Thesis); }
 * }
 * ```
 */
interface Agent
{
    public string $id { get; }

    public string $name { get; }

    public Output $output { get; }

    public string $purpose { get; }

    public Context $context { get; }

    public Effects $effects { get; }

    public Provider\Needs $provider { get; }

    public Capabilities $capabilities { get; }

    public Transport\Needs $transport { get; }
}
