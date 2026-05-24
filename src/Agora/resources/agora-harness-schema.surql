DEFINE TABLE agora_session TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD title ON TABLE agora_session TYPE option<string>;
DEFINE FIELD status ON TABLE agora_session TYPE string ASSERT $value INSIDE ['active', 'paused', 'completed', 'failed', 'cancelled'];
DEFINE FIELD model ON TABLE agora_session TYPE option<string>;
DEFINE FIELD provider ON TABLE agora_session TYPE option<string>;
DEFINE FIELD working_directory ON TABLE agora_session TYPE option<string>;
DEFINE FIELD started_at ON TABLE agora_session TYPE datetime;
DEFINE FIELD completed_at ON TABLE agora_session TYPE option<datetime>;
DEFINE FIELD metadata ON TABLE agora_session TYPE option<object> FLEXIBLE;
DEFINE INDEX agora_session_status_started ON TABLE agora_session FIELDS status, started_at;

DEFINE TABLE agora_turn TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_turn TYPE record<agora_session>;
DEFINE FIELD sequence ON TABLE agora_turn TYPE int ASSERT $value >= 1;
DEFINE FIELD actor ON TABLE agora_turn TYPE string ASSERT $value INSIDE ['user', 'assistant', 'system', 'tool', 'runtime'];
DEFINE FIELD status ON TABLE agora_turn TYPE string ASSERT $value INSIDE ['streaming', 'waiting', 'completed', 'failed', 'cancelled'];
DEFINE FIELD prompt_hash ON TABLE agora_turn TYPE option<string>;
DEFINE FIELD started_at ON TABLE agora_turn TYPE datetime;
DEFINE FIELD completed_at ON TABLE agora_turn TYPE option<datetime>;
DEFINE FIELD metadata ON TABLE agora_turn TYPE option<object> FLEXIBLE;
DEFINE INDEX agora_turn_session_sequence ON TABLE agora_turn FIELDS session_id, sequence UNIQUE;
DEFINE INDEX agora_turn_session_status ON TABLE agora_turn FIELDS session_id, status;

DEFINE TABLE agora_event TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_event TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_event TYPE option<record<agora_turn>>;
DEFINE FIELD sequence ON TABLE agora_event TYPE int ASSERT $value >= 1;
DEFINE FIELD cue_id ON TABLE agora_event TYPE option<string>;
DEFINE FIELD cue_type ON TABLE agora_event TYPE string;
DEFINE FIELD channel ON TABLE agora_event TYPE option<string>;
DEFINE FIELD source ON TABLE agora_event TYPE string ASSERT $value INSIDE ['panoply', 'athena', 'agora', 'runtime'];
DEFINE FIELD payload ON TABLE agora_event TYPE object FLEXIBLE;
DEFINE FIELD occurred_at ON TABLE agora_event TYPE datetime;
DEFINE FIELD received_at ON TABLE agora_event TYPE datetime;
DEFINE INDEX agora_event_session_sequence ON TABLE agora_event FIELDS session_id, sequence UNIQUE;
DEFINE INDEX agora_event_turn_sequence ON TABLE agora_event FIELDS turn_id, sequence;
DEFINE INDEX agora_event_cue_type ON TABLE agora_event FIELDS cue_type;

DEFINE TABLE agora_tool_call TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_tool_call TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_tool_call TYPE record<agora_turn>;
DEFINE FIELD event_id ON TABLE agora_tool_call TYPE record<agora_event>;
DEFINE FIELD call_id ON TABLE agora_tool_call TYPE string;
DEFINE FIELD tool_name ON TABLE agora_tool_call TYPE string;
DEFINE FIELD arguments_hash ON TABLE agora_tool_call TYPE option<string>;
DEFINE FIELD arguments ON TABLE agora_tool_call TYPE option<object> FLEXIBLE;
DEFINE FIELD result ON TABLE agora_tool_call TYPE option<object> FLEXIBLE;
DEFINE FIELD status ON TABLE agora_tool_call TYPE string ASSERT $value INSIDE ['requested', 'running', 'completed', 'failed', 'denied'];
DEFINE FIELD started_at ON TABLE agora_tool_call TYPE datetime;
DEFINE FIELD completed_at ON TABLE agora_tool_call TYPE option<datetime>;
DEFINE INDEX agora_tool_call_turn ON TABLE agora_tool_call FIELDS turn_id, call_id UNIQUE;
DEFINE INDEX agora_tool_call_status ON TABLE agora_tool_call FIELDS session_id, status;

DEFINE TABLE agora_mcp_call TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_mcp_call TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_mcp_call TYPE record<agora_turn>;
DEFINE FIELD event_id ON TABLE agora_mcp_call TYPE record<agora_event>;
DEFINE FIELD server_name ON TABLE agora_mcp_call TYPE string;
DEFINE FIELD tool_name ON TABLE agora_mcp_call TYPE string;
DEFINE FIELD request_id ON TABLE agora_mcp_call TYPE string;
DEFINE FIELD arguments_hash ON TABLE agora_mcp_call TYPE option<string>;
DEFINE FIELD arguments ON TABLE agora_mcp_call TYPE option<object> FLEXIBLE;
DEFINE FIELD result ON TABLE agora_mcp_call TYPE option<object> FLEXIBLE;
DEFINE FIELD status ON TABLE agora_mcp_call TYPE string ASSERT $value INSIDE ['requested', 'running', 'completed', 'failed', 'denied'];
DEFINE FIELD started_at ON TABLE agora_mcp_call TYPE datetime;
DEFINE FIELD completed_at ON TABLE agora_mcp_call TYPE option<datetime>;
DEFINE INDEX agora_mcp_call_turn ON TABLE agora_mcp_call FIELDS turn_id, request_id UNIQUE;
DEFINE INDEX agora_mcp_call_status ON TABLE agora_mcp_call FIELDS session_id, status;

DEFINE TABLE agora_effect TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_effect TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_effect TYPE record<agora_turn>;
DEFINE FIELD event_id ON TABLE agora_effect TYPE record<agora_event>;
DEFINE FIELD effect_id ON TABLE agora_effect TYPE string;
DEFINE FIELD kind ON TABLE agora_effect TYPE string;
DEFINE FIELD summary ON TABLE agora_effect TYPE string;
DEFINE FIELD arguments_hash ON TABLE agora_effect TYPE option<string>;
DEFINE FIELD arguments ON TABLE agora_effect TYPE option<object> FLEXIBLE;
DEFINE FIELD hazard_level ON TABLE agora_effect TYPE option<string>;
DEFINE FIELD resolution ON TABLE agora_effect TYPE option<string>;
DEFINE FIELD outcome ON TABLE agora_effect TYPE option<object> FLEXIBLE;
DEFINE FIELD status ON TABLE agora_effect TYPE string ASSERT $value INSIDE ['requested', 'authorized', 'paused', 'denied', 'executed', 'failed', 'cancelled'];
DEFINE FIELD requested_at ON TABLE agora_effect TYPE datetime;
DEFINE FIELD completed_at ON TABLE agora_effect TYPE option<datetime>;
DEFINE INDEX agora_effect_turn ON TABLE agora_effect FIELDS turn_id, effect_id UNIQUE;
DEFINE INDEX agora_effect_status ON TABLE agora_effect FIELDS session_id, status;

DEFINE TABLE agora_approval TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_approval TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_approval TYPE record<agora_turn>;
DEFINE FIELD effect_record_id ON TABLE agora_approval TYPE record<agora_effect>;
DEFINE FIELD effect_id ON TABLE agora_approval TYPE string;
DEFINE FIELD grant_id ON TABLE agora_approval TYPE option<string>;
DEFINE FIELD subject ON TABLE agora_approval TYPE string;
DEFINE FIELD mode ON TABLE agora_approval TYPE string ASSERT $value INSIDE ['once', 'session', 'always', 'dynamic'];
DEFINE FIELD hazard_level ON TABLE agora_approval TYPE option<string>;
DEFINE FIELD decision ON TABLE agora_approval TYPE string ASSERT $value INSIDE ['pending', 'approved', 'denied', 'cancelled'];
DEFINE FIELD requested_at ON TABLE agora_approval TYPE datetime;
DEFINE FIELD decided_at ON TABLE agora_approval TYPE option<datetime>;
DEFINE FIELD reason ON TABLE agora_approval TYPE option<string>;
DEFINE INDEX agora_approval_effect ON TABLE agora_approval FIELDS effect_record_id;
DEFINE INDEX agora_approval_session_decision ON TABLE agora_approval FIELDS session_id, decision;

DEFINE TABLE agora_artifact TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_artifact TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_artifact TYPE record<agora_turn>;
DEFINE FIELD event_id ON TABLE agora_artifact TYPE record<agora_event>;
DEFINE FIELD artifact_id ON TABLE agora_artifact TYPE string;
DEFINE FIELD kind ON TABLE agora_artifact TYPE string;
DEFINE FIELD title ON TABLE agora_artifact TYPE option<string>;
DEFINE FIELD content_hash ON TABLE agora_artifact TYPE string;
DEFINE FIELD media_type ON TABLE agora_artifact TYPE option<string>;
DEFINE FIELD payload ON TABLE agora_artifact TYPE object FLEXIBLE;
DEFINE FIELD created_at ON TABLE agora_artifact TYPE datetime;
DEFINE INDEX agora_artifact_turn ON TABLE agora_artifact FIELDS turn_id, artifact_id UNIQUE;
DEFINE INDEX agora_artifact_kind ON TABLE agora_artifact FIELDS session_id, kind;

DEFINE TABLE agora_usage TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_usage TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_usage TYPE option<record<agora_turn>>;
DEFINE FIELD event_id ON TABLE agora_usage TYPE option<record<agora_event>>;
DEFINE FIELD provider ON TABLE agora_usage TYPE option<string>;
DEFINE FIELD model ON TABLE agora_usage TYPE option<string>;
DEFINE FIELD input_tokens ON TABLE agora_usage TYPE option<int>;
DEFINE FIELD output_tokens ON TABLE agora_usage TYPE option<int>;
DEFINE FIELD cache_read_tokens ON TABLE agora_usage TYPE option<int>;
DEFINE FIELD cache_write_tokens ON TABLE agora_usage TYPE option<int>;
DEFINE FIELD total_tokens ON TABLE agora_usage TYPE option<int>;
DEFINE FIELD cost_usd ON TABLE agora_usage TYPE option<float>;
DEFINE FIELD recorded_at ON TABLE agora_usage TYPE datetime;
DEFINE INDEX agora_usage_turn ON TABLE agora_usage FIELDS turn_id, recorded_at;
DEFINE INDEX agora_usage_session_recorded ON TABLE agora_usage FIELDS session_id, recorded_at;

DEFINE TABLE agora_replay_checkpoint TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_replay_checkpoint TYPE record<agora_session>;
DEFINE FIELD sequence ON TABLE agora_replay_checkpoint TYPE int ASSERT $value >= 1;
DEFINE FIELD state_kind ON TABLE agora_replay_checkpoint TYPE string ASSERT $value INSIDE ['conversation', 'runtime', 'activity', 'workspace'];
DEFINE FIELD projection_hash ON TABLE agora_replay_checkpoint TYPE string;
DEFINE FIELD state ON TABLE agora_replay_checkpoint TYPE object FLEXIBLE;
DEFINE FIELD created_at ON TABLE agora_replay_checkpoint TYPE datetime;
DEFINE INDEX agora_replay_checkpoint_session_sequence ON TABLE agora_replay_checkpoint FIELDS session_id, sequence, state_kind UNIQUE;

DEFINE TABLE agora_resume_state TYPE NORMAL SCHEMAFULL
    PERMISSIONS FOR select, create WHERE true, FOR update, delete NONE;
DEFINE FIELD session_id ON TABLE agora_resume_state TYPE record<agora_session>;
DEFINE FIELD turn_id ON TABLE agora_resume_state TYPE option<record<agora_turn>>;
DEFINE FIELD event_sequence ON TABLE agora_resume_state TYPE int ASSERT $value >= 0;
DEFINE FIELD status ON TABLE agora_resume_state TYPE string ASSERT $value INSIDE ['ready', 'waiting_approval', 'streaming', 'failed', 'cancelled'];
DEFINE FIELD pending_effect_record_id ON TABLE agora_resume_state TYPE option<record<agora_effect>>;
DEFINE FIELD serialized_context ON TABLE agora_resume_state TYPE object FLEXIBLE;
DEFINE FIELD updated_at ON TABLE agora_resume_state TYPE datetime;
DEFINE INDEX agora_resume_state_session ON TABLE agora_resume_state FIELDS session_id UNIQUE;
