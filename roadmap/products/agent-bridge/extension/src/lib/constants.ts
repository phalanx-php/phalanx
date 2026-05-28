export const DAEMON_DEFAULT_PORT = 9078
export const WS_RECONNECT_BASE_MS = 1000
export const WS_RECONNECT_MAX_MS = 30_000
export const WS_RECONNECT_JITTER_MS = 1000
export const OUTBOUND_BUFFER_SIZE = 64
export const OUTBOUND_BUFFER_PRESSURE_THRESHOLD = 16
export const ACTION_TIMEOUT_MS = 30_000
export const SW_EVALUATE_TIMEOUT_MS = 10_000
export const SW_NETWORK_WAIT_TIMEOUT_MS = 10_000
export const NATIVE_HOST_NAME = "com.phalanx.bridge"

// WebSocket bufferedAmount backpressure thresholds
export const BUFFERED_AMOUNT_PAUSE = 1_048_576 // 1MB
export const BUFFERED_AMOUNT_RESUME = 524_288 // 512KB
