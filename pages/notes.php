<?php
require_once __DIR__ . '/../assets/php/db.php';

$notes = [];

$result = $mysqli->query(
    "SELECT id, title, content, color, pos_x, pos_y, z_index, created_at, updated_at
     FROM notes
     ORDER BY z_index ASC, id ASC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $notes[] = $row;
    }
    $result->free();
}
?>

<div class="notes-page">

    <div class="notes-toolbar">
        <div>
            <h2 class="notes-page-title">یادداشت‌های من</h2>
            <p class="notes-page-description">
                یادداشت‌های خود را ایجاد، ویرایش، جابه‌جا و حذف کنید.
            </p>
        </div>

        <button type="button" id="createNoteButton" class="button-primary">
            <i class="fas fa-plus"></i>
            یادداشت جدید
        </button>
    </div>

    <div
        id="notesBoard"
        class="notes-board"
        data-csrf-token="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"
    >

        <?php if (empty($notes)): ?>

            <div id="notesEmptyState" class="notes-empty-state">
                <div class="notes-empty-icon">
                    <i class="fas fa-sticky-note"></i>
                </div>

                <h3>هنوز یادداشتی ایجاد نشده است</h3>

                <p>
                    برای ایجاد اولین یادداشت روی «یادداشت جدید» کلیک کنید.
                </p>
            </div>

        <?php else: ?>

            <?php foreach ($notes as $note): ?>

                <article
                    class="sticky-note"
                    data-id="<?php echo (int)$note['id']; ?>"
                    data-color="<?php echo htmlspecialchars($note['color'], ENT_QUOTES, 'UTF-8'); ?>"
                    style="
                        --note-color: <?php echo htmlspecialchars($note['color'], ENT_QUOTES, 'UTF-8'); ?>;
                        left: <?php echo (int)$note['pos_x']; ?>px;
                        top: <?php echo (int)$note['pos_y']; ?>px;
                        z-index: <?php echo (int)$note['z_index']; ?>;
                    "
                >

                    <div class="sticky-note-header drag-handle">

                        <span class="sticky-note-grip">
                            <i class="fas fa-grip-horizontal"></i>
                        </span>

                        <button
                            type="button"
                            class="sticky-note-delete"
                            title="حذف یادداشت"
                        >
                            <i class="fas fa-trash"></i>
                        </button>

                    </div>

                    <div class="sticky-note-body">

                        <input
                            type="text"
                            class="sticky-note-title"
                            value="<?php echo htmlspecialchars($note['title'], ENT_QUOTES, 'UTF-8'); ?>"
                            maxlength="150"
                            placeholder="عنوان یادداشت"
                        >

                        <textarea
                            class="sticky-note-content"
                            maxlength="5000"
                            placeholder="متن یادداشت..."
                        ><?php echo htmlspecialchars($note['content'], ENT_QUOTES, 'UTF-8'); ?></textarea>

                    </div>

                    <div class="sticky-note-footer">

                        <span class="sticky-note-status"></span>

                        <button
                            type="button"
                            class="sticky-note-save"
                        >
                            ذخیره
                        </button>

                    </div>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>