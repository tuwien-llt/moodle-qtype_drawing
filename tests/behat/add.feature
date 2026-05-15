@qtype @qtype_drawing @qtype_drawing_add
Feature: Test creating a Drawing question
  As a teacher
  In order to test my students
  I need to be able to create an drawing question

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | teacher1 | T1        | Teacher1 | teacher1@moodle.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: Create a Drawing question with Response format set to 'HTML editor'
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher1
    And I add a "drawing" question filling the form with:
      | id_name             | drawing-001                    |
      | id_questiontext     | Draw a biology cell.           |
      | id_generalfeedback  | This is general feedback       |
    Then I should see "drawing-001" in the "categoryquestions" "table"
