<?php
$error = '';
$messages = [];

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')),
        getenv('DB_USER'), getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $author = trim($_POST['author'] ?? '');
        $body   = trim($_POST['body'] ?? '');

        if ($author === '' || $body === '') {
            $error = 'Both name and message are required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO messages (author, body) VALUES (:author, :body)');
            $stmt->execute([':author' => $author, ':body' => $body]);

            // Redirect so a page refresh doesn't submit the form again
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    }

    $messages = $pdo->query('SELECT author, body, created_at FROM messages ORDER BY created_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Could not connect to the database.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AK3 LinuxServerDrift</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 480px;
            margin: 80px auto;
            padding: 0 20px;
            text-align: center;
        }
        input[type="text"], textarea {
            padding: 8px;
            font-size: 16px;
            font-family: inherit;
            width: 100%;
            box-sizing: border-box;
            margin: 12px 0;
        }
        button {
            padding: 8px 20px;
            font-size: 16px;
            cursor: pointer;
        }
        .error {
            margin-top: 24px;
            color: #c33;
        }
        .messages {
            list-style: none;
            padding: 0;
            margin-top: 32px;
            text-align: left;
        }
        .messages li {
            border-top: 1px solid #ddd;
            padding: 12px 0;
        }
        .messages .meta {
            font-size: 13px;
            color: #777;
        }
    </style>
</head>
<body>
    <h1>Welcome</h1>
    <form method="post">
        <label for="author">Test deploy</label>
        <input type="text" id="author" name="author" placeholder="Your name" required value="<?= htmlspecialchars($_POST['author'] ?? '') ?>">
        <textarea id="body" name="body" rows="3" placeholder="Write a message" required><?= htmlspecialchars($_POST['body'] ?? '') ?></textarea>
        <button type="submit">Send</button>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($messages): ?>
        <ul class="messages">
            <?php foreach ($messages as $m): ?>
                <li>
                    <div class="meta"><strong><?= htmlspecialchars($m['author']) ?></strong> · <?= htmlspecialchars(date('Y-m-d H:i', strtotime($m['created_at']))) ?></div>
                    <div><?= nl2br(htmlspecialchars($m['body'])) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html>
