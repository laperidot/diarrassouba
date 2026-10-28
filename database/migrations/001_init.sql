-- =====================================================================
-- DIARRASSOUBA IMPORT-EXPORT — schéma initial
-- Écrit pour MySQL 5.7+/MariaDB 10.3+ (utf8mb4, InnoDB).
-- bin/migrate.php l'adapte automatiquement pour SQLite (tests locaux).
--
-- Conventions :
--   * montants  : BIGINT en unités mineures (centimes ; XOF sans décimale) -> *_minor
--   * poids     : INT en grammes -> *_g
--   * dates     : DATETIME en UTC ; DATE pour les dates calendaires
--   * active_flag / billing_lock : 1 = lien actif, NULL = historique.
--     Une contrainte UNIQUE(x, flag) n'autorise qu'un seul lien actif
--     (les NULL ne sont pas comparés entre eux, en MySQL comme en SQLite).
-- =====================================================================

CREATE TABLE roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  CONSTRAINT uq_roles_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_code VARCHAR(32) NOT NULL,
  is_active TINYINT NOT NULL DEFAULT 1,
  must_change_password TINYINT NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT uq_users_email UNIQUE (email),
  CONSTRAINT fk_users_role FOREIGN KEY (role_code) REFERENCES roles (code) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  success TINYINT NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_login_key ON login_attempts (key_hash, created_at);
CREATE INDEX idx_login_ip ON login_attempts (ip_hash, created_at);

CREATE TABLE rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(40) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_rate_bucket ON rate_limits (bucket, key_hash, created_at);

CREATE TABLE sequences (
  name VARCHAR(40) NOT NULL PRIMARY KEY,
  value INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'person',
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  phone_normalized VARCHAR(15) NULL,
  phone_alt VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  country VARCHAR(2) NULL,
  city VARCHAR(80) NULL,
  address VARCHAR(255) NULL,
  default_payment_terms VARCHAR(20) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT uq_clients_code UNIQUE (code),
  CONSTRAINT fk_clients_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_clients_phone ON clients (phone_normalized);
CREATE INDEX idx_clients_name ON clients (name);

CREATE TABLE tariffs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  origin_country VARCHAR(2) NOT NULL,
  destination_country VARCHAR(2) NOT NULL,
  currency VARCHAR(3) NOT NULL,
  rate_per_kg_minor BIGINT NOT NULL,
  min_billable_g INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_tariffs_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_tariffs_route ON tariffs (origin_country, destination_country, is_active);

CREATE TABLE shipments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(30) NOT NULL,
  origin_country VARCHAR(2) NOT NULL,
  origin_city VARCHAR(80) NULL,
  destination_country VARCHAR(2) NOT NULL,
  destination_city VARCHAR(80) NULL,
  airline VARCHAR(80) NULL,
  flight_number VARCHAR(20) NULL,
  awb_number VARCHAR(40) NULL,
  carrier_reference VARCHAR(80) NULL,
  planned_departure DATETIME NULL,
  actual_departure DATETIME NULL,
  planned_arrival DATETIME NULL,
  actual_arrival DATETIME NULL,
  freight_cost_minor BIGINT NULL,
  freight_currency VARCHAR(3) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'preparation',
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT uq_shipments_reference UNIQUE (reference),
  CONSTRAINT fk_shipments_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_shipments_status ON shipments (status);
CREATE INDEX idx_shipments_created ON shipments (created_at);

CREATE TABLE parcels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tracking_code VARCHAR(20) NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  delivery_contact_name VARCHAR(150) NULL,
  delivery_contact_phone VARCHAR(30) NULL,
  description VARCHAR(255) NOT NULL,
  pieces INT UNSIGNED NOT NULL DEFAULT 1,
  weight_g INT UNSIGNED NOT NULL,
  billable_weight_g INT UNSIGNED NOT NULL,
  rate_per_kg_minor BIGINT NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency VARCHAR(3) NOT NULL,
  tariff_id INT UNSIGNED NULL,
  origin_country VARCHAR(2) NOT NULL,
  origin_city VARCHAR(80) NULL,
  destination_country VARCHAR(2) NOT NULL,
  destination_city VARCHAR(80) NULL,
  received_at DATETIME NOT NULL,
  shipment_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'registered',
  status_changed_at DATETIME NOT NULL,
  public_weight TINYINT NOT NULL DEFAULT 1,
  payment_terms VARCHAR(20) NULL,
  internal_notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT uq_parcels_tracking UNIQUE (tracking_code),
  CONSTRAINT fk_parcels_sender FOREIGN KEY (sender_id) REFERENCES clients (id),
  CONSTRAINT fk_parcels_recipient FOREIGN KEY (recipient_id) REFERENCES clients (id),
  CONSTRAINT fk_parcels_tariff FOREIGN KEY (tariff_id) REFERENCES tariffs (id) ON DELETE SET NULL,
  CONSTRAINT fk_parcels_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE SET NULL,
  CONSTRAINT fk_parcels_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_parcels_status ON parcels (status);
CREATE INDEX idx_parcels_sender ON parcels (sender_id);
CREATE INDEX idx_parcels_recipient ON parcels (recipient_id);
CREATE INDEX idx_parcels_shipment ON parcels (shipment_id);
CREATE INDEX idx_parcels_received ON parcels (received_at);

CREATE TABLE parcel_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(160) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  size INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_patt_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id) ON DELETE CASCADE,
  CONSTRAINT fk_patt_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_patt_parcel ON parcel_attachments (parcel_id);

CREATE TABLE shipment_parcels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT UNSIGNED NOT NULL,
  parcel_id INT UNSIGNED NOT NULL,
  active_flag TINYINT NULL DEFAULT 1,
  added_by INT UNSIGNED NULL,
  added_at DATETIME NOT NULL,
  removed_by INT UNSIGNED NULL,
  removed_at DATETIME NULL,
  CONSTRAINT uq_sp_active UNIQUE (parcel_id, active_flag),
  CONSTRAINT fk_sp_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id),
  CONSTRAINT fk_sp_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
  CONSTRAINT fk_sp_added FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_sp_removed FOREIGN KEY (removed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_sp_shipment ON shipment_parcels (shipment_id, active_flag);

CREATE TABLE tracking_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  previous_status VARCHAR(20) NULL,
  location VARCHAR(120) NULL,
  public_comment VARCHAR(255) NULL,
  internal_comment VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_te_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id) ON DELETE CASCADE,
  CONSTRAINT fk_te_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_te_parcel ON tracking_events (parcel_id, occurred_at);
CREATE INDEX idx_te_created ON tracking_events (created_at);

CREATE TABLE invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'invoice',
  client_id INT UNSIGNED NOT NULL,
  shipment_id INT UNSIGNED NULL,
  credit_of_invoice_id INT UNSIGNED NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  currency VARCHAR(3) NOT NULL,
  payment_terms VARCHAR(20) NULL,
  issue_date DATE NULL,
  due_date DATE NULL,
  subtotal_minor BIGINT NOT NULL DEFAULT 0,
  fees_minor BIGINT NOT NULL DEFAULT 0,
  tax_rate_bp INT NOT NULL DEFAULT 0,
  tax_minor BIGINT NOT NULL DEFAULT 0,
  total_minor BIGINT NOT NULL DEFAULT 0,
  paid_minor BIGINT NOT NULL DEFAULT 0,
  client_snapshot TEXT NULL,
  notes VARCHAR(500) NULL,
  validated_at DATETIME NULL,
  validated_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancelled_by INT UNSIGNED NULL,
  cancel_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT uq_invoices_number UNIQUE (number),
  CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES clients (id),
  CONSTRAINT fk_inv_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_credit FOREIGN KEY (credit_of_invoice_id) REFERENCES invoices (id),
  CONSTRAINT fk_inv_validated FOREIGN KEY (validated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_cancelled FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_inv_client ON invoices (client_id);
CREATE INDEX idx_inv_status ON invoices (status, due_date);
CREATE INDEX idx_inv_validated ON invoices (validated_at);

CREATE TABLE invoice_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  parcel_id INT UNSIGNED NULL,
  tracking_code VARCHAR(20) NULL,
  description VARCHAR(255) NOT NULL,
  billable_weight_g INT UNSIGNED NOT NULL DEFAULT 0,
  rate_per_kg_minor BIGINT NOT NULL DEFAULT 0,
  transport_minor BIGINT NOT NULL DEFAULT 0,
  fees_minor BIGINT NOT NULL DEFAULT 0,
  fees_label VARCHAR(120) NULL,
  line_total_minor BIGINT NOT NULL,
  billing_lock TINYINT NULL DEFAULT 1,
  CONSTRAINT uq_items_billing UNIQUE (parcel_id, billing_lock),
  CONSTRAINT fk_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_items_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_items_invoice ON invoice_items (invoice_id);

CREATE TABLE invoice_installments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  amount_minor BIGINT NOT NULL,
  label VARCHAR(80) NULL,
  CONSTRAINT fk_inst_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_inst_invoice ON invoice_installments (invoice_id, due_date);

CREATE TABLE payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  receipt_number VARCHAR(30) NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency VARCHAR(3) NOT NULL,
  method VARCHAR(20) NOT NULL,
  reference VARCHAR(80) NULL,
  confirmation VARCHAR(10) NOT NULL DEFAULT 'manual',
  paid_at DATETIME NOT NULL,
  notes VARCHAR(255) NULL,
  reversal_of_id INT UNSIGNED NULL,
  reversed_by_id INT UNSIGNED NULL,
  form_token CHAR(32) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT uq_pay_receipt UNIQUE (receipt_number),
  CONSTRAINT uq_pay_reference UNIQUE (method, reference),
  CONSTRAINT uq_pay_token UNIQUE (form_token),
  CONSTRAINT uq_pay_reversal UNIQUE (reversal_of_id),
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
  CONSTRAINT fk_pay_reversal FOREIGN KEY (reversal_of_id) REFERENCES payments (id),
  CONSTRAINT fk_pay_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_pay_invoice ON payments (invoice_id);
CREATE INDEX idx_pay_paid ON payments (paid_at);

CREATE TABLE expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(20) NOT NULL,
  shipment_id INT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  ticket_reference VARCHAR(60) NULL,
  airline VARCHAR(80) NULL,
  beneficiary VARCHAR(150) NULL,
  supplier_reference VARCHAR(80) NULL,
  expense_date DATE NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency VARCHAR(3) NOT NULL,
  payment_status VARCHAR(10) NOT NULL DEFAULT 'unpaid',
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  dedupe_key VARCHAR(120) NULL,
  cancel_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT uq_exp_dedupe UNIQUE (dedupe_key),
  CONSTRAINT fk_exp_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE SET NULL,
  CONSTRAINT fk_exp_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_exp_date ON expenses (expense_date);
CREATE INDEX idx_exp_shipment ON expenses (shipment_id);
CREATE INDEX idx_exp_category ON expenses (category, status);

CREATE TABLE expense_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  expense_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(160) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  size INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_eatt_expense FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE CASCADE,
  CONSTRAINT fk_eatt_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_eatt_expense ON expense_attachments (expense_id);

CREATE TABLE deliveries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT UNSIGNED NOT NULL,
  mode VARCHAR(10) NOT NULL,
  agent_id INT UNSIGNED NULL,
  planned_date DATE NULL,
  address VARCHAR(255) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'planned',
  attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
  recipient_name VARCHAR(150) NULL,
  recipient_id_note VARCHAR(120) NULL,
  incident VARCHAR(500) NULL,
  confirmed_at DATETIME NULL,
  confirmed_by INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_del_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
  CONSTRAINT fk_del_agent FOREIGN KEY (agent_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_del_confirmed FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_del_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_del_parcel ON deliveries (parcel_id);
CREATE INDEX idx_del_agent ON deliveries (agent_id, status);
CREATE INDEX idx_del_status ON deliveries (status, planned_date);

CREATE TABLE delivery_proofs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(160) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  size INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_dp_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE,
  CONSTRAINT fk_dp_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_dp_delivery ON delivery_proofs (delivery_id);

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  channel VARCHAR(10) NOT NULL,
  event VARCHAR(40) NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(500) NULL,
  entity_type VARCHAR(30) NULL,
  entity_id INT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL,
  error VARCHAR(255) NULL,
  read_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_notif_user ON notifications (user_id, read_at);
CREATE INDEX idx_notif_created ON notifications (created_at);

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(30) NULL,
  entity_id INT UNSIGNED NULL,
  details TEXT NULL,
  ip_hash CHAR(16) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_audit_entity ON audit_logs (entity_type, entity_id);
CREATE INDEX idx_audit_created ON audit_logs (created_at);

INSERT INTO roles (code, name, description) VALUES
 ('admin', 'Administrateur', 'Accès complet, gestion des utilisateurs'),
 ('manager', 'Responsable', 'Supervision des opérations et des finances'),
 ('registrar', 'Agent d''enregistrement', 'Clients, colis, étiquettes'),
 ('logistics', 'Agent logistique', 'Dossiers d''expédition, statuts, livraisons'),
 ('cashier', 'Comptable / caissier', 'Factures, paiements, dépenses'),
 ('courier', 'Agent de livraison', 'Ses livraisons et leur confirmation');
