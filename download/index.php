<?php
declare(strict_types=1);

require_once 'db.php';

const PER_PAGE = 10;

// Page numbers to show: first, last and the neighbours of the current page.
function page_window(int $current, int $total): array
{
    $pages = array_unique(array_filter(
        [1, $current - 1, $current, $current + 1, $total],
        fn (int $p): bool => $p >= 1 && $p <= $total
    ));
    sort($pages);

    $out = [];
    $prev = 0;
    foreach ($pages as $p) {
        if ($p - $prev > 1) {
            $out[] = '…';
        }
        $out[] = $p;
        $prev = $p;
    }

    return $out;
}

// "1 student" / "5 students".
function plural(int $n, string $one, string $many): string
{
    return number_format($n) . ' ' . ($n === 1 ? $one : $many);
}

// Tiny inline SVG trend line for the headline cards. No JavaScript needed.
function sparkline(array $values, int $w = 96, int $h = 38): string
{
    $n = count($values);
    if ($n < 2) {
        return '';
    }

    $values = array_values($values);
    $min = min($values);
    $max = max($values);
    $pad = 4;
    $points = [];
    foreach ($values as $i => $v) {
        $x = round($i * $w / ($n - 1), 1);
        $y = $max === $min
            ? $h / 2
            : round($h - $pad - (($v - $min) / ($max - $min)) * ($h - 2 * $pad), 1);
        $points[] = $x . ',' . $y;
    }

    $line = implode(' ', $points);
    [$lx, $ly] = explode(',', (string) end($points));

    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true">'
        . '<polygon points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '" fill="currentColor" fill-opacity=".1"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="' . $lx . '" cy="' . $ly . '" r="3" fill="currentColor"/>'
        . '</svg>';
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$now = new DateTimeImmutable('now');
$today = $now->setTime(0, 0);
$monthStart = $now->modify('first day of this month')->setTime(0, 0);
$lastMonthStart = $monthStart->modify('-1 month');
$weekStart = $today->modify('-6 days'); // today + the 6 days before it
$chartStart = $monthStart->modify('-11 months');
$ts = fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

/* ---------- Headline numbers ---------- */

$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS total,
            COUNT(DISTINCT course) AS courses,
            COALESCE(SUM(created_at >= ?), 0) AS this_month,
            COALESCE(SUM(created_at >= ? AND created_at < ?), 0) AS last_month,
            COALESCE(SUM(created_at >= ?), 0) AS this_week
     FROM students'
);
$stmt->execute([$ts($monthStart), $ts($lastMonthStart), $ts($monthStart), $ts($weekStart)]);
$stats = array_map('intval', $stmt->fetch());

$diff = $stats['this_month'] - $stats['last_month'];
if ($diff > 0) {
    $delta = ['delta-up', '+' . $diff . ' vs last month'];
} elseif ($diff < 0) {
    $delta = ['delta-down', '−' . abs($diff) . ' vs last month'];
} else {
    $delta = ['', 'Same as last month'];
}

/* ---------- New students per month (last 12 months) ---------- */

$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
     FROM students
     WHERE created_at >= ?
     GROUP BY ym"
);
$stmt->execute([$ts($chartStart)]);
$monthly = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$chartLabels = [];
$chartTitles = [];
$chartValues = [];
for ($i = 0; $i < 12; $i++) {
    $month = $chartStart->modify("+$i months");
    $chartLabels[] = $month->format('M');
    $chartTitles[] = $month->format('F Y');
    $chartValues[] = (int) ($monthly[$month->format('Y-m')] ?? 0);
}

// Running total at the end of each month (for the "Total students" trend line).
$running = $stats['total'] - array_sum($chartValues);
$cumulative = [];
foreach ($chartValues as $v) {
    $running += $v;
    $cumulative[] = $running;
}

/* ---------- New students per day (last 7 days) ---------- */

$stmt = $pdo->prepare(
    'SELECT DATE(created_at) AS d, COUNT(*) AS total
     FROM students
     WHERE created_at >= ?
     GROUP BY d'
);
$stmt->execute([$ts($weekStart)]);
$daily = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$weekValues = [];
for ($i = 0; $i < 7; $i++) {
    $weekValues[] = (int) ($daily[$weekStart->modify("+$i days")->format('Y-m-d')] ?? 0);
}

/* ---------- Students per course (top 6) + latest additions ---------- */

$topCourses = $pdo->query(
    'SELECT course, COUNT(*) AS total
     FROM students
     GROUP BY course
     ORDER BY total DESC, course ASC
     LIMIT 6'
)->fetchAll();

// Doughnut: the top five courses, everything else grouped as "Other".
$donut = [];
$shown = 0;
foreach (array_slice($topCourses, 0, 5) as $row) {
    $donut[] = ['name' => (string) $row['course'], 'count' => (int) $row['total'], 'other' => false];
    $shown += (int) $row['total'];
}
if ($stats['total'] > $shown) {
    $donut[] = ['name' => 'Other', 'count' => $stats['total'] - $shown, 'other' => true];
}

$latest = $pdo->query('SELECT id, name, course, created_at FROM students ORDER BY id DESC LIMIT 6')->fetchAll();

/* ---------- Student list (search, sort, pagination) ---------- */

$search = trim((string) ($_GET['q'] ?? ''));
$course = trim((string) ($_GET['course'] ?? ''));
$sortOptions = student_sort_options();
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

$conditions = [];
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $conditions[] = '(name LIKE ? OR email LIKE ? OR course LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($course !== '') {
    $conditions[] = 'course = ?';
    $params[] = $course;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$filtered = $search !== '' || $course !== '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students $where");
$stmt->execute($params);
$matchCount = (int) $stmt->fetchColumn();

$pages = max(1, (int) ceil($matchCount / PER_PAGE));
$page = min(positive_int($_GET['page'] ?? null) ?? 1, $pages);
$offset = ($page - 1) * PER_PAGE;

// ORDER BY comes from a whitelist; LIMIT/OFFSET are integers we computed.
$stmt = $pdo->prepare(
    'SELECT id, name, email, course, created_at
     FROM students ' . $where . '
     ORDER BY ' . $sortOptions[$sort]['sql'] . '
     LIMIT ' . PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Current list state, so links and the delete form can return to the same view.
$listState = function (array $override = []) use ($search, $course, $sort, $page): array {
    $state = array_merge(['q' => $search, 'course' => $course, 'sort' => $sort, 'page' => $page], $override);
    if ($state['q'] === '') {
        unset($state['q']);
    }
    if ($state['course'] === '') {
        unset($state['course']);
    }
    if ($state['sort'] === 'newest') {
        unset($state['sort']);
    }
    if ((int) $state['page'] <= 1) {
        unset($state['page']);
    }

    return $state;
};
$listUrl = function (array $override = []) use ($listState): string {
    $state = $listState($override);

    return 'index.php' . ($state ? '?' . http_build_query($state) : '') . '#students';
};
$backQuery = http_build_query($listState());

// The student that was just added or edited flashes briefly in the list.
$highlightId = (int) ($_SESSION['highlight'] ?? 0);
unset($_SESSION['highlight']);

// Clickable column headers. Clicking the active one goes back to "newest first".
$sortHead = fn (string $label, bool $active, bool $asc, string $next): string =>
    '<a href="' . e($listUrl(['sort' => $next, 'page' => 1])) . '" class="th-sort'
    . ($active ? ' is-active' : '') . ($asc ? ' is-asc' : '') . '">' . e($label) . '</a>';

/* ---------- Copy for the page ---------- */

$hour = (int) $now->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

if ($stats['total'] === 0) {
    $heroText = 'Your student list is empty. Add the first student to start tracking enrollment.';
} else {
    $heroText = plural($stats['total'], 'student', 'students') . ' enrolled across '
        . plural($stats['courses'], 'course', 'courses') . '. '
        . ($stats['this_week'] > 0
            ? plural($stats['this_week'], 'student joined', 'students joined') . ' in the last 7 days.'
            : 'No new students in the last 7 days.');
}

$avg = $stats['courses'] > 0
    ? rtrim(rtrim(number_format($stats['total'] / $stats['courses'], 1), '0'), '.')
    : '0';

$kpis = [
    [
        'label' => 'Total students', 'value' => $stats['total'], 'icon' => 'users', 'tone' => 0,
        'series' => array_slice($cumulative, -6),
        'foot' => $stats['courses'] > 0 ? 'Avg. ' . e($avg) . ' per course' : 'Nothing enrolled yet',
    ],
    [
        'label' => 'Courses', 'value' => $stats['courses'], 'icon' => 'book', 'tone' => 2,
        'series' => [], 'seg' => true,
        'foot' => $topCourses ? 'Most popular: ' . e($topCourses[0]['course']) : 'No courses yet',
    ],
    [
        'label' => 'Added this month', 'value' => $stats['this_month'], 'icon' => 'calendar', 'tone' => 3,
        'series' => array_slice($chartValues, -6),
        'foot' => '<span class="delta ' . e($delta[0]) . '">' . e($delta[1]) . '</span>',
    ],
    [
        'label' => 'Last 7 days', 'value' => $stats['this_week'], 'icon' => 'trending', 'tone' => 1,
        'series' => $weekValues,
        'foot' => 'Since ' . e($weekStart->format('D, M j')),
    ],
];

$maxWeek = max(max($weekValues), 1);
$weekLabel = implode(', ', array_map(
    fn (int $i, int $v): string => $weekStart->modify("+$i days")->format('D') . ' ' . $v,
    array_keys($weekValues),
    $weekValues
));

render_header('Dashboard', 'dashboard');
?>

<header class="page-head dash-head">
    <div>
        <p class="dash-date"><?= e($now->format('l, j F Y')) ?></p>
        <h1><?= e($greeting) ?></h1>
        <p class="dash-sub"><?= e($heroText) ?></p>
    </div>
    <div class="head-actions">
        <a href="create.php" class="btn btn-primary"><?= icon('plus', 16) ?> Add student</a>
    </div>
</header>

<section class="kpis" aria-label="Summary">
    <?php foreach ($kpis as $i => $k): ?>
        <article class="panel kpi tone-<?= e($k['tone']) ?> reveal" style="--i: <?= $i + 1 ?>">
            <div class="kpi-top">
                <span class="kpi-label"><?= e($k['label']) ?></span>
                <span class="kpi-icon"><?= icon($k['icon'], 18) ?></span>
            </div>
            <div class="kpi-body">
                <span class="kpi-value" data-count="<?= e($k['value']) ?>"><?= e(number_format($k['value'])) ?></span>
                <?= sparkline($k['series']) ?>
            </div>
            <?php if (!empty($k['seg']) && $topCourses): ?>
                <div class="seg" aria-hidden="true">
                    <?php foreach (array_slice($topCourses, 0, 5) as $j => $row): ?>
                        <span style="flex: <?= (int) $row['total'] ?>; background: rgb(var(--t<?= $j ?>))"></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="kpi-foot"><?= $k['foot'] ?></div>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel directory reveal" id="students" aria-label="Student list" style="--i: 9">
    <div class="panel-head">
        <div>
            <h2>Student directory</h2>
            <span class="sub">
                <?= $filtered
                    ? e(plural($matchCount, 'student matches', 'students match')) . ' your filters'
                    : e(plural($stats['total'], 'student', 'students')) . ' in total' ?>
            </span>
        </div>
        <?php if ($students): ?>
            <div class="range-toggle density-toggle" role="group" aria-label="Row density">
                <button type="button" class="is-active" data-density="comfortable" aria-pressed="true">Comfortable</button>
                <button type="button" data-density="compact" aria-pressed="false">Compact</button>
            </div>
        <?php endif; ?>
    </div>

    <form method="GET" action="index.php" class="toolbar" role="search">
        <?php if ($course !== ''): ?>
            <input type="hidden" name="course" value="<?= e($course) ?>">
        <?php endif; ?>
        <div class="search-field">
            <?= icon('search') ?>
            <input type="search" name="q" id="search" class="form-control"
                   placeholder="Search by name, email or course"
                   aria-label="Search students" value="<?= e($search) ?>">
            <kbd class="d-none d-md-inline" aria-hidden="true">/</kbd>
        </div>
        <select name="sort" class="form-select d-md-none" aria-label="Sort students" onchange="this.form.submit()">
            <?php foreach ($sortOptions as $key => $option): ?>
                <option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filtered): ?>
            <a href="<?= e($listUrl(['q' => '', 'course' => '', 'page' => 1])) ?>" class="btn btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($topCourses): ?>
        <nav class="filters" aria-label="Filter by course">
            <a href="<?= e($listUrl(['course' => '', 'page' => 1])) ?>"
               class="pill<?= $course === '' ? ' is-active' : '' ?>"<?= $course === '' ? ' aria-current="true"' : '' ?>>All</a>
            <?php
            $pillCourses = array_column($topCourses, 'total', 'course');
            if ($course !== '' && !isset($pillCourses[$course])) {
                $pillCourses = [$course => null] + $pillCourses;
            }
            foreach ($pillCourses as $name => $count):
                $active = (string) $name === $course;
                ?>
                <a href="<?= e($listUrl(['course' => $active ? '' : (string) $name, 'page' => 1])) ?>"
                   class="pill<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="true"' : '' ?>>
                    <?= e($name) ?><?php if ($count !== null): ?> <span class="n"><?= e($count) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if (!$students): ?>
        <div class="empty-state">
            <?= empty_art($filtered) ?>
            <?php if ($filtered): ?>
                <h2 class="h5">No students match these filters</h2>
                <p class="text-muted mb-3">Try a different name, email or course.</p>
                <a href="<?= e($listUrl(['q' => '', 'course' => '', 'page' => 1])) ?>" class="btn btn-outline-secondary">Clear filters</a>
                <a href="create.php" class="btn btn-primary"><?= icon('plus', 16) ?> Add student</a>
            <?php else: ?>
                <h2 class="h5">No students yet</h2>
                <p class="text-muted mb-4">Add your first student to start seeing enrollment trends here.</p>
                <a href="create.php" class="btn btn-primary"><?= icon('plus', 16) ?> Add the first student</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean table-hover">
                <caption class="visually-hidden">Students</caption>
                <thead>
                <tr>
                    <th scope="col"<?= $sort === 'name' ? ' aria-sort="ascending"' : '' ?>><?= $sortHead('Student', $sort === 'name', true, $sort === 'name' ? 'newest' : 'name') ?></th>
                    <th scope="col"<?= $sort === 'course' ? ' aria-sort="ascending"' : '' ?>><?= $sortHead('Course', $sort === 'course', true, $sort === 'course' ? 'newest' : 'course') ?></th>
                    <th scope="col" class="d-none d-md-table-cell"<?= $sort === 'newest' ? ' aria-sort="descending"' : ($sort === 'oldest' ? ' aria-sort="ascending"' : '') ?>><?= $sortHead('Added', in_array($sort, ['newest', 'oldest'], true), $sort === 'oldest', $sort === 'newest' ? 'oldest' : 'newest') ?></th>
                    <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <tr<?= $highlightId === (int) $student['id'] ? ' class="is-new"' : '' ?>>
                        <td>
                            <div class="person">
                                <?= avatar($student['name']) ?>
                                <div>
                                    <div class="person-name"><a href="edit.php?id=<?= e($student['id']) ?>"><?= highlight((string) $student['name'], $search) ?></a></div>
                                    <div class="person-email">
                                        <span><?= highlight((string) $student['email'], $search) ?></span>
                                        <button type="button" class="copy-btn" data-copy="<?= e($student['email']) ?>"
                                                aria-label="Copy email for <?= e($student['name']) ?>" title="Copy email">
                                            <span class="i-copy"><?= icon('copy', 14) ?></span><span class="i-ok"><?= icon('check', 14) ?></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><?= course_chip($student['course']) ?></td>
                        <td class="cell-muted d-none d-md-table-cell">
                            <span title="<?= e(format_date($student['created_at'], 'M j, Y g:i A')) ?>"><?= e(time_ago($student['created_at'])) ?></span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn row-toggle" data-row-toggle
                                        aria-expanded="false" aria-controls="row-detail-<?= e($student['id']) ?>"
                                        title="Show details" aria-label="Show details for <?= e($student['name']) ?>"><?= icon('chevron-down', 15) ?></button>
                                <a href="edit.php?id=<?= e($student['id']) ?>" class="icon-btn"
                                   title="Edit <?= e($student['name']) ?>"
                                   aria-label="Edit <?= e($student['name']) ?>"><?= icon('edit', 15) ?></a>
                                <button type="button" class="icon-btn danger"
                                        title="Delete <?= e($student['name']) ?>"
                                        aria-label="Delete <?= e($student['name']) ?>"
                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                        data-id="<?= e($student['id']) ?>"
                                        data-name="<?= e($student['name']) ?>"><?= icon('trash', 15) ?></button>
                            </div>
                        </td>
                    </tr>
                    <tr class="row-detail" id="row-detail-<?= e($student['id']) ?>" hidden>
                        <td colspan="4">
                            <dl class="detail-grid">
                                <div><dt>Email</dt><dd><a href="mailto:<?= e($student['email']) ?>"><?= e($student['email']) ?></a></dd></div>
                                <div><dt>Course</dt><dd><?= e($student['course']) ?></dd></div>
                                <div><dt>Added</dt><dd><?= e(format_date($student['created_at'], 'F j, Y, g:i A')) ?></dd></div>
                            </dl>
                            <a href="edit.php?id=<?= e($student['id']) ?>" class="btn btn-outline-secondary btn-sm"><?= icon('edit', 15) ?> Edit student</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            <span aria-live="polite">Showing <?= e($offset + 1) ?>–<?= e($offset + count($students)) ?> of <?= e($matchCount) ?></span>
            <?php if ($pages > 1): ?>
                <nav class="pager" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= e($listUrl(['page' => $page - 1])) ?>" aria-label="Previous page"><?= icon('chevron-left', 16) ?></a>
                    <?php else: ?>
                        <span class="is-disabled" aria-hidden="true"><?= icon('chevron-left', 16) ?></span>
                    <?php endif; ?>

                    <?php foreach (page_window($page, $pages) as $p): ?>
                        <?php if ($p === '…'): ?>
                            <span class="gap" aria-hidden="true">…</span>
                        <?php elseif ($p === $page): ?>
                            <span class="is-current" aria-current="page"><?= e($p) ?></span>
                        <?php else: ?>
                            <a href="<?= e($listUrl(['page' => $p])) ?>" aria-label="Page <?= e($p) ?>"><?= e($p) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($page < $pages): ?>
                        <a href="<?= e($listUrl(['page' => $page + 1])) ?>" aria-label="Next page"><?= icon('chevron-right', 16) ?></a>
                    <?php else: ?>
                        <span class="is-disabled" aria-hidden="true"><?= icon('chevron-right', 16) ?></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($stats['total'] > 0): ?>
    <div class="dash-grid">
        <section class="panel reveal" id="growth" style="--i: 5">
            <div class="panel-head">
                <div>
                    <h2>Enrollment growth</h2>
                    <span class="sub" id="growthSub">New students per month</span>
                </div>
                <div class="range-toggle" role="group" aria-label="Chart range">
                    <button type="button" class="is-active" data-range="6" aria-pressed="true">6M</button>
                    <button type="button" data-range="12" aria-pressed="false">12M</button>
                </div>
            </div>
            <div class="panel-body">
                <div class="chart-box">
                    <canvas id="growthChart" role="img"
                            aria-label="Line chart of new students per month: <?= e(implode(', ', array_map(
                                fn ($l, $v) => "$l $v",
                                $chartLabels,
                                $chartValues
                            ))) ?>"></canvas>
                </div>
            </div>
        </section>

        <section class="panel reveal" id="courses" style="--i: 6">
            <div class="panel-head">
                <div>
                    <h2>Students by course</h2>
                    <span class="sub">Share of all enrollments</span>
                </div>
            </div>
            <div class="panel-body donut-wrap">
                <div class="donut-box">
                    <canvas id="courseChart" role="img"
                            aria-label="Doughnut chart of students by course"></canvas>
                    <div class="donut-center">
                        <strong><?= e(number_format($stats['total'])) ?></strong>
                        <span><?= $stats['total'] === 1 ? 'student' : 'students' ?></span>
                    </div>
                </div>
                <ul class="legend">
                    <?php foreach ($donut as $i => $slice): ?>
                        <li style="--c: <?= $slice['other'] ? 'var(--line-strong)' : 'rgb(var(--t' . $i . '))' ?>">
                            <span class="dot"></span>
                            <span class="name" title="<?= e($slice['name']) ?>"><?= e($slice['name']) ?></span>
                            <span class="num"><?= e($slice['count']) ?></span>
                            <span class="pct"><?= e(round($slice['count'] / $stats['total'] * 100)) ?>%</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>

    <div class="dash-grid">
        <section class="panel reveal" style="--i: 7">
            <div class="panel-head">
                <div>
                    <h2>Recent activity</h2>
                    <span class="sub">The latest students to join</span>
                </div>
                <a href="#students" class="btn btn-outline-secondary btn-sm">View all</a>
            </div>
            <div class="panel-body">
                <ol class="timeline">
                    <?php foreach ($latest as $row): ?>
                        <li class="tone-<?= e(tone($row['name'])) ?>">
                            <span class="what">
                                <a href="edit.php?id=<?= e($row['id']) ?>"><?= e($row['name']) ?></a>
                                <span class="text-muted">joined</span>
                                <?= course_chip($row['course']) ?>
                            </span>
                            <span class="when"><?= e(time_ago($row['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>

        <section class="panel reveal" style="--i: 8">
            <div class="panel-head">
                <div>
                    <h2>This week</h2>
                    <span class="sub"><?= e(plural($stats['this_week'], 'new student', 'new students')) ?> in 7 days</span>
                </div>
            </div>
            <div class="panel-body">
                <div class="week" role="img" aria-label="New students per day: <?= e($weekLabel) ?>">
                    <?php foreach ($weekValues as $i => $v): ?>
                        <div class="week-col<?= $i === 6 ? ' is-today' : '' ?>">
                            <span class="week-n"><?= e($v) ?></span>
                            <span class="week-bar"><span style="height: <?= $v > 0 ? max(10, (int) round($v / $maxWeek * 100)) : 3 ?>%"></span></span>
                            <span class="week-d"><?= e($weekStart->modify("+$i days")->format('D')) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
<?php endif; ?>

<!-- Delete confirmation -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="delete.php" class="modal-content" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="deleteId" value="">
            <input type="hidden" name="back" value="<?= e($backQuery) ?>">

            <div class="modal-body p-4">
                <div class="d-flex gap-3">
                    <span class="modal-icon"><?= icon('trash', 20) ?></span>
                    <div>
                        <h2 class="h5 mb-1" id="deleteModalLabel">Delete this student?</h2>
                        <p class="mb-0 text-muted"><strong class="text-body" id="deleteName"></strong> will be removed permanently. This can't be undone.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep student</button>
                <button type="submit" class="btn btn-danger">Delete students</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('deleteModal').addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) { return; }
        document.getElementById('deleteId').value = trigger.getAttribute('data-id');
        document.getElementById('deleteName').textContent = trigger.getAttribute('data-name');
    });

    // Keyboard shortcuts: "/" focuses search, "N" opens the add form, Esc leaves the search box.
    document.addEventListener('keydown', function (e) {
        if (e.metaKey || e.ctrlKey || e.altKey) { return; }
        var t = e.target;
        var typing = t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable);
        if (typing) {
            if (e.key === 'Escape' && t.id === 'search') { t.blur(); }
            return;
        }
        if (document.querySelector('.modal.show')) { return; }
        if (e.key === '/') {
            var box = document.getElementById('search');
            if (!box) { return; }
            e.preventDefault();
            box.focus();
            box.select();
        } else if (e.key === 'n' || e.key === 'N') {
            window.location.href = 'create.php';
        }
    });

    // Row density (comfortable / compact), remembered in this browser.
    (function () {
        var box = document.getElementById('students');
        var btns = document.querySelectorAll('[data-density]');
        function apply(mode) {
            box.classList.toggle('is-compact', mode === 'compact');
            btns.forEach(function (b) {
                var on = b.getAttribute('data-density') === mode;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        var saved = 'comfortable';
        try { saved = localStorage.getItem('sm-density') || saved; } catch (err) {}
        apply(saved);
        btns.forEach(function (b) {
            b.addEventListener('click', function () {
                var mode = b.getAttribute('data-density');
                apply(mode);
                try { localStorage.setItem('sm-density', mode); } catch (err) {}
            });
        });
    })();

    // Show or hide a student's details under their row.
    document.querySelectorAll('[data-row-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var detail = document.getElementById(btn.getAttribute('aria-controls'));
            var open = detail.hidden;
            detail.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.title = open ? 'Hide details' : 'Show details';
            btn.closest('tr').classList.toggle('is-open', open);
        });
    });

    // Copy an email address to the clipboard.
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!navigator.clipboard) { return; }
            navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
                btn.classList.add('copied');
                setTimeout(function () { btn.classList.remove('copied'); }, 1200);
            });
        });
    });
</script>

<?php if ($stats['total'] > 0): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            if (typeof Chart === 'undefined') { return; }

            var labels = <?= json_encode($chartLabels, $jsonFlags) ?>;
            var titles = <?= json_encode($chartTitles, $jsonFlags) ?>;
            var values = <?= json_encode($chartValues) ?>;
            var names = <?= json_encode(array_column($donut, 'name'), $jsonFlags) ?>;
            var counts = <?= json_encode(array_column($donut, 'count')) ?>;
            var isOther = <?= json_encode(array_column($donut, 'other')) ?>;
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var range = 6;

            // Colours come from the CSS variables so the charts follow light/dark mode.
            function css(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
            function palette() {
                return {
                    t: [0, 1, 2, 3, 4].map(function (i) { return css('--t' + i); }),
                    other: css('--line-strong'), grid: css('--line'), tick: css('--muted'),
                    ink: css('--ink'), bg: css('--bg'), surface: css('--surface')
                };
            }
            function sliceColors(p) {
                return names.map(function (_, i) { return isOther[i] ? p.other : 'rgb(' + p.t[i] + ')'; });
            }
            function visible(list) { return list.slice(-range); }

            var p = palette();
            Chart.defaults.font.family = '"Public Sans", system-ui, sans-serif';
            Chart.defaults.color = p.tick;

            function tooltip() {
                return { backgroundColor: p.ink, titleColor: p.bg, bodyColor: p.bg, padding: 10, cornerRadius: 10, displayColors: false };
            }

            /* ----- Enrollment growth ----- */
            var growth = new Chart(document.getElementById('growthChart'), {
                type: 'line',
                data: {
                    labels: visible(labels),
                    datasets: [{
                        label: 'New students',
                        data: visible(values),
                        borderColor: 'rgb(' + p.t[0] + ')',
                        borderWidth: 2.5,
                        tension: 0.38,
                        fill: true,
                        backgroundColor: function (ctx) {
                            var area = ctx.chart.chartArea;
                            if (!area) { return 'rgba(' + p.t[0] + ',.15)'; }
                            var g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                            g.addColorStop(0, 'rgba(' + p.t[0] + ',.32)');
                            g.addColorStop(1, 'rgba(' + p.t[0] + ',0)');
                            return g;
                        },
                        pointRadius: function (c) { return c.dataIndex === c.dataset.data.length - 1 ? 5 : 0; },
                        pointHoverRadius: 6,
                        pointHitRadius: 20,
                        pointBackgroundColor: 'rgb(' + p.t[0] + ')',
                        pointBorderColor: p.surface,
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: reduceMotion ? false : { duration: 700 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign(tooltip(), {
                            callbacks: {
                                title: function (items) { return visible(titles)[items[0].dataIndex]; },
                                label: function (c) { return c.parsed.y + (c.parsed.y === 1 ? ' new student' : ' new students'); }
                            }
                        })
                    },
                    scales: {
                        x: { grid: { display: false }, border: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: p.grid }, border: { display: false } }
                    }
                }
            });

            var sub = document.getElementById('growthSub');
            function summary() {
                var n = visible(values).reduce(function (a, b) { return a + b; }, 0);
                sub.textContent = n + (n === 1 ? ' new student' : ' new students') + ' in the last ' + range + ' months';
            }
            summary();

            document.querySelectorAll('[data-range]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    range = parseInt(btn.getAttribute('data-range'), 10);
                    document.querySelectorAll('[data-range]').forEach(function (b) {
                        var on = b === btn;
                        b.classList.toggle('is-active', on);
                        b.setAttribute('aria-pressed', on ? 'true' : 'false');
                    });
                    growth.data.labels = visible(labels);
                    growth.data.datasets[0].data = visible(values);
                    growth.update();
                    summary();
                });
            });

            /* ----- Students by course ----- */
            var donut = new Chart(document.getElementById('courseChart'), {
                type: 'doughnut',
                data: {
                    labels: names,
                    datasets: [{ data: counts, backgroundColor: sliceColors(p), borderWidth: 0, spacing: 3, borderRadius: 6, hoverOffset: 4 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '74%',
                    animation: reduceMotion ? false : { duration: 700 },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign(tooltip(), {
                            callbacks: {
                                label: function (c) { return c.label + ': ' + c.parsed + (c.parsed === 1 ? ' student' : ' students'); }
                            }
                        })
                    }
                }
            });

            window.addEventListener('themechange', function () {
                p = palette();
                Chart.defaults.color = p.tick;

                var ds = growth.data.datasets[0];
                ds.borderColor = 'rgb(' + p.t[0] + ')';
                ds.pointBackgroundColor = ds.borderColor;
                ds.pointBorderColor = p.surface;
                growth.options.scales.y.grid.color = p.grid;
                ['x', 'y'].forEach(function (axis) {
                    var scale = growth.options.scales[axis];
                    if (scale && scale.ticks) { scale.ticks.color = p.tick; }
                });

                [growth, donut].forEach(function (chart) {
                    var tip = chart.options.plugins.tooltip;
                    tip.backgroundColor = p.ink;
                    tip.titleColor = p.bg;
                    tip.bodyColor = p.bg;
                });
                donut.data.datasets[0].backgroundColor = sliceColors(p);

                growth.update('none');
                donut.update('none');
            });
        })();
    </script>
<?php endif; ?>

<?php render_footer(); ?>
