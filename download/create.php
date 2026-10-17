<?php
declare(strict_types=1);

require_once 'db.php';

$values = ['name' => '', 'email' => '', 'course' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    [$values, $errors] = validate_student($_POST);

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO students (name, email, course)
                 VALUES (:name, :email, :course)'
            );
            $stmt->execute($values);
            $_SESSION['highlight'] = (int) $pdo->lastInsertId();

            flash('success', $values['name'] . ' was added.');
            redirect('index.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors['email'] = 'A student with this email address already exists.';
            } else {
                error_log($e->getMessage());
                $errors['form'] = 'The student could not be saved. Please try again.';
            }
        }
    }
}

render_header('Add student', 'create');
?>

<a href="index.php" class="back-link"><?= icon('arrow-left', 16) ?> Back to dashboard</a>

<div class="page-head">
    <div>
        <h1>Add student</h1>
        <p>Name, email and course are all you need.</p>
    </div>
</div>

<?php render_student_form($values, $errors, 'Save student', course_list($pdo)); ?>

<?php render_footer(); ?>
