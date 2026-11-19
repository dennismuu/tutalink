USE tutalink;

INSERT INTO tutor_teaching_levels (name, sort_order) VALUES
('PP1', 1),
('PP2', 2),
('Grade 1', 3),
('Grade 2', 4),
('Grade 3', 5),
('Grade 4', 6),
('Grade 5', 7),
('Grade 6', 8),
('Grade 7', 9),
('Grade 8', 10),
('Grade 9', 11),
('Senior School', 12);

INSERT IGNORE INTO provider_teaching_levels (provider_id, level_id)
SELECT existing.provider_id, replacement.id
FROM provider_teaching_levels existing
JOIN tutor_teaching_levels previous ON previous.id = existing.level_id
JOIN tutor_teaching_levels replacement
  ON (previous.name = 'Primary' AND replacement.name IN (
        'PP1', 'PP2', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'
     ))
  OR (previous.name = 'Junior' AND replacement.name IN ('Grade 7', 'Grade 8', 'Grade 9'))
  OR (previous.name IN ('Secondary', 'KCSE') AND replacement.name = 'Senior School');

UPDATE tutor_teaching_levels
SET is_active = 0
WHERE name IN ('Primary', 'Junior', 'Secondary', 'KCSE');

UPDATE service_categories
SET is_active = 0
WHERE module = 'tutoring' AND LOWER(name) IN ('primary', 'junior', 'secondary');
