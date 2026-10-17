<?php
declare(strict_types=1);

require_once 'db.php';

// Deleting (and undoing a delete) changes data, so it is POST only and CSRF protected.
require_post();
verifyCsrf();

// Return to the same search / sort / page the person was on. Only known keys are
// accepted and rebuilt, so this can never redirect anywhere but index.php.
function back_url($raw): string
{
    parse_str(is_string($raw) ? $raw : '', $state);

    $back = [];
    if (isset($state['q']) && is_string($state['q']) && $state['q'] !== '' && mb_strlen($state['q']) <= 100) {
        $back['q'] = $state['q'];
    }
    if (isset($state['course']) && is_string($state['course']) && $state['course'] !== '' && mb_strlen($state['course']) <= 100) {
        $back['course'] = $state['course'];
    }
    if (isset($state['sort']) && is_string($state['sort']) && $state['sort'] !== 'newest'
        && isset(student_sort_options()[$state['sort']])) {
        $back['sort'] = $state['sort'];
    }
    $backPage = positive_int($state['page'] ?? null);
    if ($backPage !== null && $backPage > 1) {
        $back['page'] = $backPage;
    }

    return 'index.php' . ($back ? '?' . http_build_query($back) : '');
}

$rawBack = $_POST['back'] ?? '';

// Undo: put back the student that was deleted a moment ago.
if (isset($_POST['undo'])) {
    $undo = $_SESSION['undo'] ?? null;
    unset($_SESSION['undo']);

    if (is_array($undo)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO students (id, name, email, course, created_at)
                 VALUES (:id, :name, :email, :course, :created_at)'
            );
            $stmt->execute($undo);
            flash('success', $undo['name'] . ' was restored.');
        } catch (PDOException $e) {
            error_log($e->getMessage());
            flash('warning', 'This student could not be restored. Their email may already be in use.');
        }
    } else {
        flash('warning', 'There is nothing to restore.');
    }

    redirect(back_url($rawBack));
}

$id = positive_int($_POST['id'] ?? null);

if ($id === null) {
    render_error_page(400, 'Invalid request', 'The student ID is not valid.');
}

$stmt = $pdo->prepare('SELECT id, name, email, course, created_at FROM students WHERE id = ?');
$stmt->execute([$id]);
$student = $stmt->fetch();

$stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
$stmt->execute([$id]);

if ($student && $stmt->rowCount() > 0) {
    $_SESSION['undo'] = $student;
    flash('success', $student['name'] . ' was deleted.', ['undo' => true, 'back' => is_string($rawBack) ? $rawBack : '']);
} else {
    flash('warning', 'Student not found. It may have already been deleted.');
}

redirect(back_url($rawBack));
