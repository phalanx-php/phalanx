export type SignalType = 'invalidate' | 'flash' | 'redirect' | 'event' | 'token'

export type InvalidateSignal = {
  type: 'invalidate'
  keys: string[]
}

export type FlashSignal = {
  type: 'flash'
  message: string
  level: 'success' | 'error' | 'warning' | 'info'
}

export type RedirectSignal = {
  type: 'redirect'
  to: string
  replace: boolean
}

export type EventSignal = {
  type: 'event'
  name: string
  payload: unknown
}

export type TokenSignal = {
  type: 'token'
  token: string | null
  expires_in: number | null
}

export type Signal =
  | InvalidateSignal
  | RedirectSignal
  | FlashSignal
  | EventSignal
  | TokenSignal

export type Envelope<T = unknown> = {
  data: T
  meta: {
    signals: Signal[]
    timestamp: number
    trace_id: string | null
  }
}

export type SignalClientConfig = {
  onFlash?: (message: string, level: FlashSignal['level']) => void
  onRedirect?: (to: string, replace: boolean) => void
  onInvalidate?: (keys: string[]) => void
  onToken?: (token: string | null, expiresIn: number | null) => void
}

export function createSignalClient(config: SignalClientConfig = {}) {
  function processSignals(signals: Signal[]): void {
    for (const signal of signals) {
      switch (signal.type) {
        case 'invalidate':
          if (config.onInvalidate) {
            config.onInvalidate(signal.keys)
          }
          break

        case 'token':
          if (config.onToken) {
            config.onToken(signal.token, signal.expires_in)
          }
          break

        case 'flash':
          if (config.onFlash) {
            config.onFlash(signal.message, signal.level)
          } else {
            window.dispatchEvent(
              new CustomEvent('phalanx:flash', { detail: signal }),
            )
          }
          break

        case 'event':
          window.dispatchEvent(
            new CustomEvent(`phalanx:${signal.name}`, {
              detail: signal.payload,
            }),
          )
          break

        case 'redirect':
          if (config.onRedirect) {
            queueMicrotask(() => config.onRedirect!(signal.to, signal.replace))
          }
          break
      }
    }
  }

  function unwrap<T>(envelope: Envelope<T>): T {
    processSignals(envelope.meta.signals)
    return envelope.data
  }

  function isEnvelope(data: unknown): data is Envelope {
    return (
      typeof data === 'object' &&
      data !== null &&
      'data' in data &&
      'meta' in data &&
      typeof (data as Envelope).meta === 'object' &&
      (data as Envelope).meta !== null &&
      'signals' in (data as Envelope).meta
    )
  }

  return { processSignals, unwrap, isEnvelope }
}
