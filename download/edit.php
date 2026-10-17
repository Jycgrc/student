<?php
declare(strict_types=1);

require_once 'db.php';

$id = positive_int($_GET['id'] ?? null);

if ($id === null) {
    render_error_page(400, 'Invalid request', 'The student ID is not valid.');
}

$stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    render_error_page(404, 'Student not found', 'This student may have been deleted.');
}

$values = [
    'name' => $student['name'],
    'email' => $student['email'],
    'course' => $student['course'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // On failure the submitted values stay in the form.
    [$values, $errors] = validate_student($_POST);

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE students
                 SET name = :name, email = :email, course = :course
                 WHERE id = :id'
            );
            $stmt->execute($values + ['id' => $id]);
            $_SESSION['highlight'] = $id;

            flash('success', 'Changes to ' . $values['name'] . ' were saved.');
            redirect('index.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors['email'] = 'Another student already uses this email address.';
            } else {
                error_log($e->getMessage());
                $errors['form'] = 'The changes could not be saved. Please try again.';
            }
        }
    }
}

render_header('Edit student', 'students');
?>

<a href="index.php" class="back-link"><?= icon('arrow-left', 16) ?> Back to dashboard</a>

<div class="page-head">
    <div class="person">
        <?= avatar($student['name'], 'md') ?>
        <div>
            <h1>Edit student</h1>
            <p>On the roster since <?= e(format_date($student['created_at'], 'F j, Y')) ?></p>
        </div>
    </div>
</div>

<?php render_student_form($values, $errors, 'Save changes', course_list($pdo)); ?>

<section class="panel danger-zone" aria-labelledby="danger-title">
    <div>
        <h2 id="danger-title">Delete this student</h2>
        <p>Permanently removes the record. This can't be undone.</p>
    </div>
    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
        <?= icon('trash', 16) ?> Delete student
    </button>
</section>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="delete.php" class="modal-content" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($student['id']) ?>">

            <div class="modal-body p-4">
                <div class="d-flex gap-3">
                    <span class="modal-icon"><?= icon('trash', 20) ?></span>
                    <div>
                        <h2 class="h5 mb-1" id="deleteModalLabel">Delete this student?</h2>
                        <p class="mb-0 text-muted"><strong class="text-body"><?= e($student['name']) ?></strong> will be removed permanently. This can't be undone.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep student</button>
                <button type="submit" class="btn btn-danger">Delete student</button>
            </div>
        </form>
    </div>
</div>

<?php render_footer(); ?>
