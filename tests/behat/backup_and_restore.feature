@qtype @qtype_drawing @qtype_drawing_backup
Feature: Test duplicating a quiz containing a drawing question
  As a teacher
  In order re-use my courses containing drawing questions
  I need to be able to backup and restore them

  Background:
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype       | name        | template         |
      | Test questions   | drawing     | drawing-001 | plain            |
      | Test questions   | drawing     | drawing-002 | plain            |
      | Test questions   | drawing     | drawing-003 | plain            |
    And the following "activities" exist:
      | activity   | name      | course | idnumber |
      | quiz       | Test quiz | C1     | quiz1    |
    And quiz "Test quiz" contains the following questions:
      | drawing-001 | 1 |
      | drawing-002 | 1 |
      | drawing-003 | 1 |
    And the following config values are set as admin:
      | enableasyncbackup | 0 |

  @javascript
  Scenario: Backup and restore a course containing one drawing question
    When I am on the "Course 1" course page logged in as admin
    And I backup "Course 1" course using this options:
      | Confirmation | Filename | test_drawing_backup.mbz |
    And I restore "test_drawing_backup.mbz" backup into a new course using this options:
      | Schema | Course name       | Course 2 |
      | Schema | Course short name | C2       |
    And I am on the "Course 2" "core_question > course question bank" page
    And I choose "Edit question" action for "drawing-001" in the question bank
    Then the following fields match these values:
      | id_name                    | drawing-001                                               |
      | id_generalfeedback         | I hope your drawing had a beginning, a middle and an end. |
    And I press "Cancel"
    And I choose "Edit question" action for "drawing-002" in the question bank
    Then the following fields match these values:
      | id_name                    | drawing-002                                               |
      | id_generalfeedback         | I hope your drawing had a beginning, a middle and an end. |
    And I press "Cancel"
    And I choose "Edit question" action for "drawing-003" in the question bank
    Then the following fields match these values:
      | id_name                    | drawing-003                                               |
      | id_generalfeedback         | I hope your drawing had a beginning, a middle and an end. |
