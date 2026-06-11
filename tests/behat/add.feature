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

  @_file_upload @javascript
  Scenario: Create a Drawing question with image background
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher1
    And I press "Create a new question ..."
    And I set the field "Freehand drawing" to "1"
    And I click on "Add" "button" in the "Choose a question type to add" "dialogue"
    And I set the field "id_name" to "drawing-002"
    And I set the field "id_questiontext" to "Draw on blue background."
    And I set the field "id_generalfeedback" to "This is general feedback"
    And I expand all fieldsets
    And I upload "question/type/drawing/tests/behat/fixtures/question_bg.png" file to "File" filemanager
    And I press "id_submitbutton"
    Then I should see "drawing-002"
