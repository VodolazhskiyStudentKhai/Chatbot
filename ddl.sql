CREATE TABLE IF NOT EXISTS `user_list` (
                                                        `id_user` INT NOT NULL,
                                                        `first_name` VARCHAR(64) NULL,
                                                        `second_name` VARCHAR(64) NULL,
                                                        `nickname` VARCHAR(32) NULL,
                                                        `id_role` INT DEFAULT 0,
                                                        PRIMARY KEY (`id_user`));

CREATE TABLE IF NOT EXISTS `events` (
                                        `event_id` INT NOT NULL AUTO_INCREMENT,
                                        `event_name` VARCHAR(64) NOT NULL,
                                        `event_date` DATE NOT NULL,
                                        `event_info` VARCHAR(4096),
                                        PRIMARY KEY (`event_id`)
);
CREATE TABLE IF NOT EXISTS pending_events (
                                              user_id BIGINT PRIMARY KEY,
                                              step INT DEFAULT 0,
                                              event_name VARCHAR(64),
                                              event_date DATE,
                                              event_info TEXT
);

CREATE TABLE tests (
                       test_id INT AUTO_INCREMENT PRIMARY KEY,
                       test_name VARCHAR(255) NOT NULL
);

CREATE TABLE questions (
                           id INT AUTO_INCREMENT PRIMARY KEY,
                           question_text VARCHAR(4096) NOT NULL,
                           options JSON NOT NULL,
                           correct_option INT NOT NULL
);
CREATE TABLE test_questions (
                                test_id INT NOT NULL,
                                question_id INT NOT NULL,
                                PRIMARY KEY (test_id, question_id),
                                FOREIGN KEY (test_id) REFERENCES tests(test_id) ON DELETE CASCADE,
                                FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE pending_tests (
                               user_id BIGINT NOT NULL PRIMARY KEY,
                               test_id INT NOT NULL,
                               question_id INT DEFAULT 0,
                               step INT DEFAULT 0,
                               FOREIGN KEY (test_id) REFERENCES tests(test_id) ON DELETE CASCADE
);

CREATE TABLE test_results (
                              user_id BIGINT NOT NULL,
                              test_id INT NOT NULL,
                              question_id INT NOT NULL,
                              selected_option INT NOT NULL,
                              is_correct BOOLEAN NOT NULL,
                              attempt INT NOT NULL DEFAULT 0,
                              answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                              PRIMARY KEY (user_id, test_id, question_id, attempt),
                              FOREIGN KEY (test_id) REFERENCES tests(test_id) ON DELETE CASCADE,
                              FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);


CREATE TABLE event_registrations (
                                     user_id INT NOT NULL,
                                     event_id INT NOT NULL,
                                     registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     PRIMARY KEY (user_id, event_id),
                                     FOREIGN KEY (user_id) REFERENCES user_list(id_user) ON DELETE CASCADE,
                                     FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
);
CREATE INDEX idx_user_id ON test_results(user_id);
