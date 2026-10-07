-- A freelancer's profile beyond the offer itself: an hourly rate,
-- availability, a short bio per language, a list of skills, and a
-- portfolio of past work. One row per provider; a provider who never
-- filled this in simply has none, and nothing of it shows on their page.
CREATE TABLE x_freelancer_profile (
    provider_id INT UNSIGNED PRIMARY KEY,
    hourly_rate INT UNSIGNED NULL,
    availability ENUM('available', 'busy', 'paused') NOT NULL DEFAULT 'available',
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_x_freelancer_profile_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_freelancer_profile_translation (
    provider_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    bio VARCHAR(500) NOT NULL,
    PRIMARY KEY (provider_id, locale),
    CONSTRAINT fk_x_freelancer_profile_translation FOREIGN KEY (provider_id) REFERENCES x_freelancer_profile (provider_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Free-text tags, not a fixed catalogue - kept simple, like the rest of
-- this extension's own fields.
CREATE TABLE x_freelancer_skill (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    name VARCHAR(30) NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_x_freelancer_skill (provider_id, name),
    CONSTRAINT fk_x_freelancer_skill_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The picture's file lives under var/uploads/freelancer-portfolio/<accountId>/,
-- keyed by account rather than provider, so an account's own files can be
-- removed in one step on AccountDeleted even after the provider row (and
-- this one, cascaded from it) is already gone.
CREATE TABLE x_freelancer_portfolio_item (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    image_name VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_x_freelancer_portfolio_item_provider (provider_id, position),
    CONSTRAINT fk_x_freelancer_portfolio_item_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_freelancer_portfolio_translation (
    item_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL,
    PRIMARY KEY (item_id, locale),
    CONSTRAINT fk_x_freelancer_portfolio_translation FOREIGN KEY (item_id) REFERENCES x_freelancer_portfolio_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
