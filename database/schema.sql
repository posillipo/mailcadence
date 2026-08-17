-- Schema database MailCadence.
-- Viene applicato automaticamente al primo avvio da src/db.php (vedi ensureSchema()) quando la
-- tabella "contacts" non esiste ancora, quindi normalmente non serve importarlo a mano. Resta qui
-- come riferimento e come via di ripiego per import manuale.

CREATE TABLE IF NOT EXISTS contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NULL,
  status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contacts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_list_members (
  list_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (list_id, contact_id),
  CONSTRAINT fk_clm_list FOREIGN KEY (list_id) REFERENCES contact_lists(id) ON DELETE CASCADE,
  CONSTRAINT fk_clm_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  from_name VARCHAR(255) NOT NULL,
  from_email VARCHAR(255) NOT NULL,
  list_id INT UNSIGNED NOT NULL,
  -- Dimensione del gruppo inviato a ogni "tick" del worker e intervallo minimo (in minuti) da
  -- rispettare tra un gruppo e il successivo: sono per-campagna (non fissi nel codice) proprio
  -- per poter rallentare o velocizzare la cadenza a seconda del provider SMTP usato, senza
  -- toccare la configurazione del server.
  batch_size INT UNSIGNED NOT NULL DEFAULT 50,
  interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  status ENUM('draft','running','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  next_batch_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  CONSTRAINT fk_campaigns_list FOREIGN KEY (list_id) REFERENCES contact_lists(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account amministratore creato tramite la pagina /setup.php al primo avvio (in alternativa a
-- ADMIN_EMAIL/ADMIN_PASSWORD_HASH nel .env, che restano comunque supportate). Una sola riga.
CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_recipients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  sent_at DATETIME NULL,
  error VARCHAR(500) NULL,
  UNIQUE KEY uq_campaign_contact (campaign_id, contact_id),
  KEY idx_campaign_status (campaign_id, status),
  CONSTRAINT fk_cr_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
