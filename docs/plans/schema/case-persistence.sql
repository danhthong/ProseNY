-- CourtFlow case persistence schema (Plan 16)
-- Mirrors wp_prose_* tables installed by prose-core Database_Installer.
-- Replace {prefix} with your WordPress table prefix (default: wp_).

-- Core case aggregate
CREATE TABLE {prefix}prose_cases (
  case_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  workflow_key varchar(64) NOT NULL DEFAULT '',
  court_routing varchar(32) NOT NULL DEFAULT '',
  county varchar(32) NOT NULL DEFAULT '',
  current_node varchar(64) NOT NULL DEFAULT '',
  current_package varchar(64) NOT NULL DEFAULT '',
  progress_percentage int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  title varchar(191) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  answers longtext NULL,
  opened_at datetime NULL,
  closed_at datetime NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (case_id),
  KEY idx_workflow (workflow_key),
  KEY idx_status (status),
  KEY idx_user (user_id),
  KEY idx_node (current_node)
);

-- Package progress per case
CREATE TABLE {prefix}prose_case_packages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  package_key varchar(64) NOT NULL DEFAULT '',
  package_id bigint(20) unsigned NULL,
  state varchar(20) NOT NULL DEFAULT 'LOCKED',
  sequence int(11) NOT NULL DEFAULT 0,
  completed_at datetime NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  UNIQUE KEY uq_case_pkg (case_id, package_key),
  KEY idx_case (case_id, sequence),
  KEY idx_state (state),
  KEY idx_pkg_key (package_key)
);

-- Forms tracked for a case package
CREATE TABLE {prefix}prose_case_forms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  package_key varchar(64) NOT NULL DEFAULT '',
  form_code varchar(64) NOT NULL DEFAULT '',
  form_id bigint(20) unsigned NULL,
  requirement varchar(16) NOT NULL DEFAULT 'required',
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_case (case_id),
  KEY idx_pkg (package_key),
  KEY idx_code (form_code)
);

-- Case timeline / audit events
CREATE TABLE {prefix}prose_case_events (
  event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  event_type varchar(64) NOT NULL DEFAULT '',
  from_node varchar(64) NOT NULL DEFAULT '',
  to_node varchar(64) NOT NULL DEFAULT '',
  payload longtext NULL,
  occurred_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (event_id),
  KEY idx_case (case_id, occurred_at),
  KEY idx_type (event_type)
);

-- Per-case deadlines (optional timeline engine output)
CREATE TABLE {prefix}prose_case_deadlines (
  case_deadline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  workflow_key varchar(64) NOT NULL DEFAULT '',
  node_id bigint(20) unsigned NULL,
  deadline_rule_id bigint(20) unsigned NOT NULL,
  title varchar(191) NOT NULL DEFAULT '',
  due_date datetime NOT NULL,
  completed tinyint(1) NOT NULL DEFAULT 0,
  completed_at datetime NULL,
  source_event varchar(64) NOT NULL DEFAULT '',
  source_event_date datetime NULL,
  day_type varchar(12) NOT NULL DEFAULT 'calendar',
  status varchar(20) NOT NULL DEFAULT 'pending',
  county varchar(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (case_deadline_id),
  UNIQUE KEY uq_case_rule_event (case_id, deadline_rule_id, source_event),
  KEY idx_case_due (case_id, due_date),
  KEY idx_due (due_date, completed)
);

-- Notes
-- * Guest sessions map to user_id = 0; link on login via session token import (future).
-- * answers JSON stores the full case_profile structure from intake.
-- * Plugin uninstall policy: data retained by default (see uninstall.php).

-- User conversations (logged-in chat persistence)
CREATE TABLE {prefix}prose_conversations (
  conversation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  case_id bigint(20) unsigned NULL,
  session_id varchar(36) NOT NULL DEFAULT '',
  title varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (conversation_id),
  UNIQUE KEY uq_session (session_id),
  KEY idx_user (user_id, updated_at),
  KEY idx_case (case_id)
);

-- Chat messages per conversation
CREATE TABLE {prefix}prose_messages (
  message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  role varchar(16) NOT NULL DEFAULT 'user',
  content longtext NOT NULL,
  sequence int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (message_id),
  KEY idx_conversation (conversation_id, sequence)
);

-- User-facing generated/uploaded documents
CREATE TABLE {prefix}prose_documents (
  document_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  case_id bigint(20) unsigned NOT NULL DEFAULT 0,
  conversation_id bigint(20) unsigned NULL,
  document_type varchar(32) NOT NULL DEFAULT 'generated_pdf',
  form_code varchar(64) NOT NULL DEFAULT '',
  title varchar(191) NOT NULL DEFAULT '',
  file_path varchar(255) NOT NULL DEFAULT '',
  download_token varchar(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (document_id),
  KEY idx_user (user_id, created_at),
  KEY idx_case (case_id),
  KEY idx_token (download_token)
);
