<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit;
}

$projectsDir = __DIR__ . '/projects';
$uploadsDir = __DIR__ . '/uploads';

if (!is_dir($projectsDir)) mkdir($projectsDir, 0775, true);
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0775, true);

function respond($arr) {
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function clean_id($id) {
  return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$id);
}

function body_json() {
  $raw = file_get_contents('php://input');
  return json_decode($raw, true) ?: [];
}

$action = $_GET['action'] ?? 'list';

if ($action === 'testwrite') {
  $file = __DIR__ . '/projects/test.txt';
  $ok = file_put_contents($file, date('c'));
  respond([
    'ok' => $ok !== false,
    'dir' => __DIR__,
    'projects_dir' => __DIR__ . '/projects',
    'writable' => is_writable(__DIR__ . '/projects'),
    'error' => error_get_last()
  ]);
}

if ($action === 'list') {
  $items = [];
  foreach (glob($projectsDir . '/*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (is_array($data)) $items[] = $data;
  }
  usort($items, function($a, $b) {
    return strcmp($b['updatedAt'] ?? '', $a['updatedAt'] ?? '');
  });
  respond(['ok' => true, 'projects' => $items]);
}

if ($action === 'save') {
  $data = body_json();
  $project = $data['project'] ?? null;

  if (!$project || !is_array($project)) {
    respond(['ok' => false, 'error' => 'Нет данных проекта', 'received' => $data]);
  }

  $id = clean_id($project['id'] ?? '');

  if (!$id) {
    $id = uniqid('project_', true);
    $project['id'] = $id;
  }

  $project['updatedAt'] = date('c');
  $file = $projectsDir . '/' . $id . '.json';
  $json = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

  $ok = file_put_contents($file, $json);

  if ($ok === false) {
    respond([
      'ok' => false,
      'error' => 'Не удалось записать файл',
      'file' => $file,
      'writable' => is_writable($projectsDir),
      'php_error' => error_get_last()
    ]);
  }

  respond(['ok' => true, 'project' => $project, 'file' => basename($file)]);
}

if ($action === 'delete') {
  $data = body_json();
  $id = clean_id($data['id'] ?? '');

  if ($id) {
    $file = $projectsDir . '/' . $id . '.json';
    if (file_exists($file)) unlink($file);
  }

  respond(['ok' => true]);
}

if ($action === 'upload') {
  if (!isset($_FILES['file'])) {
    respond(['ok' => false, 'error' => 'Файл не передан']);
  }

  $projectId = clean_id($_POST['projectId'] ?? 'project');
  $original = $_FILES['file']['name'] ?? 'file';
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

  $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'];

  if (!in_array($ext, $allowed)) {
    respond(['ok' => false, 'error' => 'Недопустимый формат файла']);
  }

  $name = $projectId . '_' . date('Ymd_His') . '_' . substr(md5($original . microtime()), 0, 6) . '.' . $ext;
  $path = $uploadsDir . '/' . $name;

  if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
    respond([
      'ok' => false,
      'error' => 'Не удалось сохранить файл',
      'writable' => is_writable($uploadsDir),
      'php_error' => error_get_last()
    ]);
  }

  respond([
    'ok' => true,
    'url' => 'uploads/' . $name
  ]);
}


if ($action === 'render') {
  $data = body_json();
  $configFile = __DIR__ . '/config.php';
  $config = file_exists($configFile) ? include $configFile : [];
  $apiKey = trim((string)($config['OPENAI_API_KEY'] ?? ''));
  $model = $config['OPENAI_IMAGE_MODEL'] ?? 'gpt-image-1';

  if (!$apiKey || $apiKey === 'PASTE_OPENAI_API_KEY_HERE' || $apiKey === 'OPENAI_API_KEY') {
    respond(['ok' => false, 'error' => 'API ключ не задан. Откройте config.php на сервере и вставьте ключ в OPENAI_API_KEY.']);
  }
  if (!function_exists('curl_init')) {
    respond(['ok' => false, 'error' => 'На сервере не включен PHP cURL. Включите расширение curl для PHP.']);
  }

  $imageRel = $data['image'] ?? '';
  $imageRel = str_replace(['..', '\\'], ['', '/'], $imageRel);
  $imagePath = __DIR__ . '/' . ltrim($imageRel, '/');

  if (!$imageRel || !file_exists($imagePath)) {
    respond(['ok' => false, 'error' => 'Не найдено исходное изображение для рендера: ' . $imageRel]);
  }

  $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
    respond(['ok' => false, 'error' => 'Для AI-рендера нужны JPG, PNG или WEBP.']);
  }
  if (filesize($imagePath) > 50 * 1024 * 1024) {
    respond(['ok' => false, 'error' => 'Изображение больше 50 МБ. Сожмите файл и загрузите снова.']);
  }

  $prompt = trim((string)($data['prompt'] ?? ''));
  $negative = trim((string)($data['negative_prompt'] ?? ''));
  if (!$prompt) respond(['ok' => false, 'error' => 'Промт пустой']);
  if (function_exists('mb_strlen') ? mb_strlen($prompt) > 12000 : strlen($prompt) > 12000) respond(['ok' => false, 'error' => 'Промт слишком длинный. Сократите пожелания или параметры.']);

  $fullPrompt = $prompt . "\n\nNegative prompt:\n" . $negative;
  $allowedSizes = ['1024x1024','1536x1024','1024x1536'];
  $size = in_array(($data['size'] ?? ''), $allowedSizes) ? $data['size'] : '1536x1024';
  $allowedQuality = ['low','medium','high'];
  $quality = in_array(($data['quality'] ?? ''), $allowedQuality) ? $data['quality'] : 'high';
  $n = max(1, min(4, intval($data['variations'] ?? 1)));

  $post = [
    'model' => $model,
    'prompt' => $fullPrompt,
    'image' => new CURLFile($imagePath),
    'size' => $size,
    'quality' => $quality,
    'n' => $n,
    'output_format' => 'png',
  ];

  if (!empty($data['control_strength']) && in_array($data['control_strength'], ['low','high'])) {
    $post['input_fidelity'] = $data['control_strength'];
  }

  $ch = curl_init('https://api.openai.com/v1/images/edits');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300,
  ]);

  $raw = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($raw === false || $http < 200 || $http >= 300) {
    respond(['ok' => false, 'error' => 'Ошибка GPT API: ' . ($err ?: $raw), 'status' => $http]);
  }

  $resp = json_decode($raw, true);
  $item = $resp['data'][0] ?? null;
  if (!$item) respond(['ok' => false, 'error' => 'API не вернул изображение', 'response' => $resp]);

  $imgBytes = null;
  if (!empty($item['b64_json'])) {
    $imgBytes = base64_decode($item['b64_json']);
  } elseif (!empty($item['url'])) {
    $imgBytes = file_get_contents($item['url']);
  }

  if (!$imgBytes) respond(['ok' => false, 'error' => 'Не удалось получить изображение из ответа API']);

  $projectId = clean_id($data['projectId'] ?? 'project');
  $outName = $projectId . '_render_' . date('Ymd_His') . '_' . substr(md5($raw . microtime()), 0, 6) . '.png';
  $outPath = $uploadsDir . '/' . $outName;
  file_put_contents($outPath, $imgBytes);

  respond(['ok' => true, 'url' => 'uploads/' . $outName, 'model' => $model, 'size' => $size]);
}

respond([
  'ok' => false,
  'error' => 'Неизвестное действие',
  'action' => $action
]);
?>