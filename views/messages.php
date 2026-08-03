<?php include __DIR__.'/header.php'; render_header('Messages'); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('home') ?></a></li>
            <?php if (!empty($messages ?? null)): ?>
                <li class="breadcrumb-item"><a href="?action=messages"><?= t('messages') ?></a></li>
                <li class="breadcrumb-item active"><?= escape($otherUsername ?? 'Conversation') ?></li>
            <?php else: ?>
                <li class="breadcrumb-item active"><?= t('messages') ?></li>
            <?php endif; ?>
        </ol>
    </nav>

    <h4 class="mb-3"><i class="fas fa-envelope me-2"></i><?= t('messages') ?></h4>

    <?php if (!empty($messages ?? null)): ?>
        <?php if (!empty($otherUsername ?? '')): ?>
            <a href="?action=messages" class="btn btn-sm btn-outline-secondary mb-3"><i class="fas fa-arrow-left me-1"></i>Back to conversations</a>
        <?php endif; ?>
        <div class="card mb-3">
            <div class="card-body">
                <?php foreach ($messages as $m): 
                    $mDate = $m['created_at'] ?? '';
                    $mFormattedDate = $mDate ? date('M j, Y H:i', strtotime($mDate)) : '';
                    $isMe = $m['sender_id'] == ($_SESSION['user_id'] ?? 0);
                ?>
                    <div class="d-flex mb-3 <?= $isMe ? 'justify-content-end' : 'justify-content-start' ?>">
                        <div class="card <?= $isMe ? 'bg-primary text-white' : 'bg-light' ?>" style="max-width: 70%;">
                            <div class="card-body py-2 px-3">
                                <p class="mb-1 small"><?= escape($m['content'] ?? '') ?></p>
                                <small class="<?= $isMe ? 'text-white-50' : 'text-muted' ?>"><i class="fas fa-clock me-1"></i><?= escape($mFormattedDate) ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <form method="POST" action="?action=messages&conversation=<?= (int)($messages[0]['sender_id'] ?? 0) ?>" class="card">
            <div class="card-body">
                <div class="input-group">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="text" name="content" class="form-control" placeholder="Type a message..." required autocomplete="off">
                    <button type="submit" class="btn btn-forum"><i class="fas fa-paper-plane me-1"></i>Send</button>
                </div>
            </div>
        </form>
    <?php elseif (!empty($conversations ?? [])): ?>
        <div class="list-group">
            <?php foreach ($conversations as $c): 
                $cDate = $c['last_message_at'] ?? '';
                $cFormattedDate = $cDate ? date('M j, Y H:i', strtotime($cDate)) : '';
            ?>
                <a href="?action=messages&conversation=<?= (int)$c['other_user_id'] ?>" class="list-group-item list-group-item-action <?= ($c['unread_count'] ?? 0) > 0 ? 'list-group-item-warning' : '' ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1"><?= escape($c['other_username'] ?? 'Unknown') ?></h6>
                            <p class="mb-1 small text-muted"><?= escape($c['last_message'] ?? '') ?></p>
                            <small class="text-muted"><i class="fas fa-clock me-1"></i><?= escape($cFormattedDate) ?></small>
                        </div>
                        <?php if (($c['unread_count'] ?? 0) > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?= (int)$c['unread_count'] ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-inbox fa-2x mb-3"></i>
                <p class="mb-0">No messages yet. Click "New message" to start a conversation.</p>
                <button class="btn btn-forum btn-sm mt-3" onclick="if(window.textmebored && window.textmebored.newConversation){window.textmebored.newConversation();}"><i class="fas fa-envelope me-1"></i>New message</button>
            </div>
        </div>
    <?php endif; ?>
<?php render_footer(); ?>