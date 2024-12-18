<?php
/***********************************************************
 * 后端逻辑部分 (PHP)
 * 包括：会话初始化、数据文件创建、辅助函数、用户注册登录、文档上传与下载
 ***********************************************************/
session_start();

// 检查是否是WebDAV请求
if (strpos($_SERVER['REQUEST_URI'], '/webdav/') === 0) {
    ob_start();
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Basic') === 0) {
            $credentials = explode(':', base64_decode(substr($auth, 6)));
            if (count($credentials) === 2) {
                $username = $credentials[0];
                $key = $credentials[1];
                
                // 验证用户名和密钥
                $users = loadJSON('data/users.json');
                foreach ($users as $user) {
                    if ($user['username'] === $username && 
                        $user['webdav_key'] === $key) {
                        $_SESSION['username'] = $username;
                        if (handleWebDAV()) {
                            return;
                        }
                        return;
                    }
                }
            }
        }
    }
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="WebDAV Server"');
    return;
}


// 处理WebDAV请求
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
    if (strpos($auth, 'Basic') === 0) {
        $credentials = explode(':', base64_decode(substr($auth, 6)));
        if (count($credentials) === 2) {
            $username = $credentials[0];
            $key = $credentials[1];
            
            // 验证用户名和密钥
            $users = loadJSON('data/users.json');
            foreach ($users as $user) {
                if ($user['username'] === $username && 
                    $user['webdav_key'] === $key) {
                    $_SESSION['username'] = $username;
                    handleWebDAV();
                    break;
                }
            }
        }
    }
}

// 创建所需数据目录及文件
if (!file_exists('data')) {
    mkdir('data', 0777, true);
}
if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
}
if (!file_exists('data/users.json')) {
    file_put_contents('data/users.json', '[]');
}
if (!file_exists('data/documents.json')) {
    file_put_contents('data/documents.json', '[]');
}

// 在创建目录的部分添加
if (!file_exists('uploads/temp')) {
    mkdir('uploads/temp', 0777, true);
}

function loadJSON($file) {
    return json_decode(file_get_contents($file), true);
}

function saveJSON($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function registerUser($username, $password) {
    $users = loadJSON('data/users.json');
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            return false;
        }
    }
    // 生成一个随机的WebDAV密钥
    $webdavKey = bin2hex(random_bytes(16));
    $users[] = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'webdav_key' => $webdavKey
    ];
    saveJSON('data/users.json', $users);
    return true;
}

function loginUser($username, $password) {
    $users = loadJSON('data/users.json');
    foreach ($users as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password'])) {
            $_SESSION['username'] = $username;
            return [
                'success' => true, 
                'username' => $username,
                'webdav_key' => $user['webdav_key']
            ];
        }
    }
    return ['success' => false];
}

function getBreadcrumb($folderId) {
    $documents = loadJSON('data/documents.json');
    $breadcrumb = [];
    
    while ($folderId !== '0') {
        foreach ($documents as $doc) {
            if ($doc['type'] === 'folder' && 
                $doc['id'] === $folderId && 
                $doc['username'] === $_SESSION['username']) {
                array_unshift($breadcrumb, [
                    'id' => $doc['id'],
                    'name' => $doc['name']
                ]);
                $folderId = $doc['parent'];
                break;
            }
        }
    }
    
    array_unshift($breadcrumb, ['id' => '0', 'name' => '我的文档']);
    return $breadcrumb;
}

function getDocuments($page = 1, $perPage = 10, $folderId = '0') {
    $documents = loadJSON('data/documents.json');
    
    // 只获取当前用户在当前文件夹下的文件和子文件夹
    $documents = array_filter($documents, function($doc) use ($folderId) {
        return ($doc['parent'] ?? '0') === $folderId && 
               $doc['username'] === $_SESSION['username'];
    });
    
    $total = count($documents);
    
    // 修改排序逻辑，同时处理文件和文件夹
    usort($documents, function($a, $b) {
        // 获取日期，文件夹用createDate，文件用uploadDate
        $dateA = isset($a['type']) && $a['type'] === 'folder' ? 
            ($a['createDate'] ?? '') : ($a['uploadDate'] ?? '');
        $dateB = isset($b['type']) && $b['type'] === 'folder' ? 
            ($b['createDate'] ?? '') : ($b['uploadDate'] ?? '');
            
        // 如果日期相同，文件夹排在前面
        if ($dateA === $dateB) {
            $isAFolder = isset($a['type']) && $a['type'] === 'folder';
            $isBFolder = isset($b['type']) && $b['type'] === 'folder';
            return $isBFolder - $isAFolder;
        }
        
        return strtotime($dateB) - strtotime($dateA);
    });
    
    $offset = ($page - 1) * $perPage;
    $documents = array_slice($documents, $offset, $perPage);
    return [
        'documents' => $documents,
        'total' => $total,
        'totalPages' => ceil($total / $perPage),
        'breadcrumb' => getBreadcrumb($folderId)
    ];
}

function handleChunkUpload() {
    $chunk = $_FILES['chunk']['tmp_name'];
    $originalName = $_POST['originalName'];
    $chunkNumber = (int)$_POST['chunkNumber'];
    $totalChunks = (int)$_POST['totalChunks'];
    $fileId = $_POST['fileId'];
    
    // 创建临时目录存储分块
    $tempDir = 'uploads/temp/' . $fileId;
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    // 保存块
    $chunkPath = $tempDir . '/' . $chunkNumber;
    if (!move_uploaded_file($chunk, $chunkPath)) {
        return ['success' => false, 'message' => '分块上传失败'];
    }
    
    // 检查是否所有分块都已上传
    if ($chunkNumber === $totalChunks - 1) {
        // 合并所有分块
        $finalPath = 'uploads/' . uniqid() . '_' . basename($originalName);
        $out = fopen($finalPath, 'wb');
        
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $tempDir . '/' . $i;
            $in = fopen($chunkPath, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            unlink($chunkPath); // 删除分块
        }
        fclose($out);
        rmdir($tempDir); // 删除临时目录
        
        // 保存文件记录
        $documents = loadJSON('data/documents.json');
        $documents[] = [
            'id' => uniqid(),
            'type' => 'file',
            'filename' => basename($finalPath),
            'originalName' => $originalName,
            'username' => $_SESSION['username'],
            'uploadDate' => date('Y-m-d H:i:s'),
            'filesize' => filesize($finalPath),
            'parent' => $_POST['parent'] ?? '0'
        ];
        saveJSON('data/documents.json', $documents);
        
        return [
            'success' => true,
            'message' => '文件上传完成',
            'isComplete' => true
        ];
    }
    
    return [
        'success' => true,
        'message' => '分块上传成功',
        'isComplete' => false
    ];
}

function uploadDocument($files, $parent = '0') {
    $documents = loadJSON('data/documents.json');
    $uploaded = [];
    if (!is_array($files)) {
        return ['success' => false, 'message' => '无效的文件数据'];
    }
    
    foreach ((array)$files['name'] as $key => $name) {
        if ($files['error'][$key] === UPLOAD_ERR_OK) {
            $filename = uniqid() . '_' . basename($name);
            if (move_uploaded_file($files['tmp_name'][$key], 'uploads/' . $filename)) {
                $filesize = filesize('uploads/' . $filename);
                $documents[] = [
                    'id' => uniqid(),
                    'type' => 'file',
                    'filename' => $filename,
                    'originalName' => $name,
                    'username' => $_SESSION['username'],
                    'uploadDate' => date('Y-m-d H:i:s'),
                    'filesize' => $filesize,
                    'parent' => $parent
                ];
                $uploaded[] = $name;
            }
        }
    }
    saveJSON('data/documents.json', $documents);
    return ['success' => true, 'message' => '成功上传 ' . count($uploaded) . ' 个文件'];
}

function downloadFile($filename) {
    $filepath = 'uploads/' . $filename;
    if (!file_exists($filepath) || strpos(realpath($filepath), realpath('uploads/')) !== 0) {
        return false;
    }

    $documents = loadJSON('data/documents.json');
    $fileInfo = null;
    
    foreach ($documents as $doc) {
        if (isset($doc['filename']) && 
            $doc['filename'] === $filename && 
            $doc['username'] === $_SESSION['username']) {
            $fileInfo = $doc;
            break;
        }
    }

    if (!$fileInfo || !isset($fileInfo['originalName'])) {
        return false;
    }

    // 设置响应头
    header('Content-Type: application/octet-stream');
    $filename = $fileInfo['originalName'];
    $encodedFilename = rawurlencode($filename);
    header('Content-Disposition: inline; filename="download"; filename*=UTF-8\'\'' . $encodedFilename);
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($filepath);
    return;
}

function deleteDocument($id, $type = 'file') {
    $documents = loadJSON('data/documents.json');
    $toDelete = [];
    
    // 如果是文件夹，递归查找所有子项
    if ($type === 'folder') {
        $toDelete = findAllChildren($documents, $id);
    }
    $toDelete[] = $id;
    
    // 删除所有相关项目
    foreach ($toDelete as $itemId) {
        foreach ($documents as $key => $doc) {
            if ($doc['id'] === $itemId) {
                if ($doc['username'] !== $_SESSION['username']) {
                    return ['success' => false, 'message' => '您没有权限删除项目'];
                }
                
                if ($doc['type'] === 'file') {
                    $filepath = 'uploads/' . $doc['filename'];
                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }
                }
                
                unset($documents[$key]);
                break;
            }
        }
    }
    
    saveJSON('data/documents.json', array_values($documents));
    return ['success' => true, 'message' => '删除成功'];
}

function findAllChildren($documents, $folderId) {
    $children = [];
    foreach ($documents as $doc) {
        if (($doc['parent'] ?? '0') === $folderId) {
            $children[] = $doc['id'];
            if ($doc['type'] === 'folder') {
                $children = array_merge($children, findAllChildren($documents, $doc['id']));
            }
        }
    }
    return $children;
}

function createFolder($folderName, $parent = '0') {
    $documents = loadJSON('data/documents.json');
    
    // 检查文件夹名是否已存在于当前目录
    foreach ($documents as $item) {
        if (isset($item['type']) && $item['type'] === 'folder' && 
            $item['name'] === $folderName && 
            $item['username'] === $_SESSION['username'] &&
            $item['parent'] === $parent) {
            return ['success' => false, 'message' => '当前目录下已存在同名文件夹'];
        }
    }
    
    // 创建新文件夹记录
    $documents[] = [
        'id' => uniqid(),
        'type' => 'folder',
        'name' => $folderName,
        'username' => $_SESSION['username'],
        'createDate' => date('Y-m-d H:i:s'),
        'parent' => $parent
    ];
    
    saveJSON('data/documents.json', $documents);
    return ['success' => true, 'message' => '文件夹创建成功'];
}

// 在其他函数定义之前添加
function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    return;
}

// 在文件开头添加验证中间件函数
function authMiddleware() {
    if(!isset($_SESSION['username'])) {
        if(isAjaxRequest()) {
            jsonResponse(['success' => false, 'message' => '请先登录']);
        } else {
            return false;
        }
        return;
    }
    return true;
}

// 添加 AJAX 请求判断函数
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// 修改搜索函数
function searchDocuments($keyword, $page = 1, $perPage = 10) {
    $documents = loadJSON('data/documents.json');
    
    // 获取当前文件夹ID
    $folderId = $_GET['folderId'] ?? '0';
    
    // 获取当前文件夹的所有子文件夹ID
    $subFolders = findAllChildren($documents, $folderId);
    $searchableFolders = array_merge([$folderId], $subFolders);
    
    // 过滤出当前用户的文档，并进行关键词匹配
    $documents = array_filter($documents, function($doc) use ($keyword, $searchableFolders) {
        // 确保是当前用户的文档且在当前文件夹或其子文件夹下
        if ($doc['username'] !== $_SESSION['username'] || 
            !in_array($doc['parent'] ?? '0', $searchableFolders)) {
            return false;
        }
        
        // 根据文档类型获取名称
        $name = $doc['type'] === 'folder' ? 
            $doc['name'] : ($doc['originalName'] ?? '');
            
        // 执行不区分大小写的搜索
        return stripos($name, $keyword) !== false;
    });
    
    // 获取总数
    $total = count($documents);
    
    // 排序逻辑保持不变
    usort($documents, function($a, $b) {
        $dateA = isset($a['type']) && $a['type'] === 'folder' ? 
            ($a['createDate'] ?? '') : ($a['uploadDate'] ?? '');
        $dateB = isset($b['type']) && $b['type'] === 'folder' ? 
            ($b['createDate'] ?? '') : ($b['uploadDate'] ?? '');
            
        if ($dateA === $dateB) {
            $isAFolder = isset($a['type']) && $a['type'] === 'folder';
            $isBFolder = isset($b['type']) && $b['type'] === 'folder';
            return $isBFolder - $isAFolder;
        }
        
        return strtotime($dateB) - strtotime($dateA);
    });
    
    // 分页
    $offset = ($page - 1) * $perPage;
    $documents = array_slice($documents, $offset, $perPage);
    
    return [
        'documents' => $documents,
        'total' => $total,
        'totalPages' => ceil($total / $perPage)
    ];
}

// 在其他函数定义后添加WebDAV相关函数

function handleWebDAV() {
    // 获取请求方法和路径
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // WebDAV根路径
    $webdavRoot = '/webdav/';
    
    // 如果请求路径不是以/webdav/开头，则不处理
    if (strpos($path, $webdavRoot) !== 0) {
        return false;
    }
    
    // 获取相对路径
    $relativePath = substr($path, strlen($webdavRoot));
    
    // 根据请求方法处理
    switch ($method) {
        case 'OPTIONS':
            header('DAV: 1, 2');
            header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, MKCOL, PROPFIND, PROPPATCH, COPY, MOVE, LOCK, UNLOCK');
            header('MS-Author-Via: DAV');
            header('Content-Length: 0');
            return true;
            
        case 'PROPFIND':
            // 获取请求深度
            $depth = isset($_SERVER['HTTP_DEPTH']) ? $_SERVER['HTTP_DEPTH'] : 'infinity';
            header('Depth: ' . $depth);
            return handlePropfind($relativePath, $depth);
            
        case 'HEAD':
            return handleWebDavGet($relativePath, true);
            
        case 'GET':
            return handleWebDavGet($relativePath, false);
            
        case 'PUT':
            return handleWebDavPut($relativePath);
            
        case 'DELETE':
            return handleWebDavDelete($relativePath);
            
        case 'MKCOL':
            return handleMkcol($relativePath);
            
        case 'LOCK':
            // 返回一个假的锁定令牌
            header('Content-Type: application/xml; charset=utf-8');
            echo '<?xml version="1.0" encoding="utf-8"?>
                  <D:prop xmlns:D="DAV:">
                      <D:lockdiscovery>
                          <D:activelock>
                              <D:locktoken>
                                  <D:href>opaquelocktoken:' . uniqid() . '</D:href>
                              </D:locktoken>
                          </D:activelock>
                      </D:lockdiscovery>
                  </D:prop>';
            return true;
            
        case 'UNLOCK':
            header('Content-Length: 0');
            return true;
    }
    
    return false;
}

function handlePropfind($path, $depth = 'infinity') {
    // 获取当前用户的文档
    $documents = loadJSON('data/documents.json');
    
    // 解码路径并移除开头和结尾的斜杠
    $path = trim(rawurldecode($path), '/');
    
    // 将路径分割成数组
    $pathParts = $path ? explode('/', $path) : [];
    
    $currentFolderId = '0';
    $currentPath = '';
    $targetItem = null;
    
    // 遍历路径部分，查找目标项目
    foreach ($pathParts as $part) {
        $found = false;
        foreach ($documents as $doc) {
            if ($doc['username'] === $_SESSION['username'] &&
                ($doc['type'] === 'folder' ? $doc['name'] : $doc['originalName']) === $part &&
                ($doc['parent'] ?? '0') === $currentFolderId) {
                if ($part === end($pathParts)) {
                    $targetItem = $doc;
                }
                $currentFolderId = $doc['id'];
                $currentPath = $currentPath ? $currentPath . '/' . $part : $part;
                $found = true;
                break;
            }
        }
        if (!$found) {
            header('HTTP/1.1 404 Not Found');
            return true;
        }
    }

    // 准备创建 XML 文档
    $DAV_NS = 'DAV:';
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true;

    // 创建根元素 <D:multistatus>
    $root = $doc->createElementNS($DAV_NS, 'D:multistatus');
    $doc->appendChild($root);

    // 添加请求的路径 response
    $response = $doc->createElementNS($DAV_NS, 'D:response');
    $root->appendChild($response);

    $href = $doc->createElementNS($DAV_NS, 'D:href');
    $href->appendChild($doc->createTextNode('/webdav/' . ($path ? $path : '')));
    $response->appendChild($href);

    $propstat = $doc->createElementNS($DAV_NS, 'D:propstat');
    $response->appendChild($propstat);

    $prop = $doc->createElementNS($DAV_NS, 'D:prop');
    $propstat->appendChild($prop);

    if (!$targetItem || $targetItem['type'] === 'folder') {
        // 文件夹或根目录
        $resourcetype = $doc->createElementNS($DAV_NS, 'D:resourcetype');
        $collection = $doc->createElementNS($DAV_NS, 'D:collection');
        $resourcetype->appendChild($collection);
        $prop->appendChild($resourcetype);

        $displayname = $doc->createElementNS($DAV_NS, 'D:displayname', $pathParts ? end($pathParts) : 'WebDAV Root');
        $prop->appendChild($displayname);

        $date = $targetItem ? $targetItem['createDate'] : date('c');
        $getlastmodified = $doc->createElementNS($DAV_NS, 'D:getlastmodified', date('r', strtotime($date)));
        $prop->appendChild($getlastmodified);

        $creationdate = $doc->createElementNS($DAV_NS, 'D:creationdate', date('c', strtotime($date)));
        $prop->appendChild($creationdate);
    } else {
        // 文件
        $resourcetype = $doc->createElementNS($DAV_NS, 'D:resourcetype');
        $prop->appendChild($resourcetype);

        $displayname = $doc->createElementNS($DAV_NS, 'D:displayname', $targetItem['originalName']);
        $prop->appendChild($displayname);

        $mimeType = mime_content_type('uploads/' . $targetItem['filename']);
        $getcontenttype = $doc->createElementNS($DAV_NS, 'D:getcontenttype', $mimeType);
        $prop->appendChild($getcontenttype);

        $getcontentlength = $doc->createElementNS($DAV_NS, 'D:getcontentlength', $targetItem['filesize']);
        $prop->appendChild($getcontentlength);

        $getlastmodified = $doc->createElementNS($DAV_NS, 'D:getlastmodified', date('r', strtotime($targetItem['uploadDate'])));
        $prop->appendChild($getlastmodified);

        $creationdate = $doc->createElementNS($DAV_NS, 'D:creationdate', date('c', strtotime($targetItem['uploadDate'])));
        $prop->appendChild($creationdate);
    }

    $status = $doc->createElementNS($DAV_NS, 'D:status', 'HTTP/1.1 200 OK');
    $propstat->appendChild($status);

    // 如果是文件夹且深度不为0，添加子项目
    if ((!$targetItem || $targetItem['type'] === 'folder') && $depth !== '0') {
        $userDocs = array_filter($documents, function($doc) use ($currentFolderId) {
            return isset($doc['username']) &&
                   $doc['username'] === $_SESSION['username'] &&
                   ($doc['parent'] ?? '0') === $currentFolderId;
        });

        foreach ($userDocs as $docItem) {
            $response = $doc->createElementNS($DAV_NS, 'D:response');
            $root->appendChild($response);

            $name = $docItem['type'] === 'folder' ? 
                    ($docItem['name'] ?? 'unnamed') : 
                    ($docItem['originalName'] ?? 'unnamed');

            // 构建完整的href路径
            $itemHref = '/webdav/';
            if ($path) {
                $itemHref .= $path . '/';
            }
            $itemHref .= rawurlencode($name);
            if ($docItem['type'] === 'folder') {
                $itemHref .= '/';
            }

            $href = $doc->createElementNS($DAV_NS, 'D:href', $itemHref);
            $response->appendChild($href);

            $propstat = $doc->createElementNS($DAV_NS, 'D:propstat');
            $response->appendChild($propstat);

            $prop = $doc->createElementNS($DAV_NS, 'D:prop');
            $propstat->appendChild($prop);

            if ($docItem['type'] === 'folder') {
                $resourcetype = $doc->createElementNS($DAV_NS, 'D:resourcetype');
                $collection = $doc->createElementNS($DAV_NS, 'D:collection');
                $resourcetype->appendChild($collection);
                $prop->appendChild($resourcetype);
            } else {
                $resourcetype = $doc->createElementNS($DAV_NS, 'D:resourcetype');
                $prop->appendChild($resourcetype);

                $mimeType = mime_content_type('uploads/' . $docItem['filename']);
                $getcontenttype = $doc->createElementNS($DAV_NS, 'D:getcontenttype', $mimeType);
                $prop->appendChild($getcontenttype);

                $getcontentlength = $doc->createElementNS($DAV_NS, 'D:getcontentlength', $docItem['filesize']);
                $prop->appendChild($getcontentlength);
            }

            $displayname = $doc->createElementNS($DAV_NS, 'D:displayname', $name);
            $prop->appendChild($displayname);

            $date = $docItem['type'] === 'folder' ? 
                    ($docItem['createDate'] ?? date('c')) : 
                    ($docItem['uploadDate'] ?? date('c'));

            $creationdate = $doc->createElementNS($DAV_NS, 'D:creationdate', date('c', strtotime($date)));
            $prop->appendChild($creationdate);

            $getlastmodified = $doc->createElementNS($DAV_NS, 'D:getlastmodified', date('r', strtotime($date)));
            $prop->appendChild($getlastmodified);

            $status = $doc->createElementNS($DAV_NS, 'D:status', 'HTTP/1.1 200 OK');
            $propstat->appendChild($status);
        }
    }

    // 确保在输出任何内容之前清除所有输出缓冲
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('HTTP/1.1 207 Multi-Status');
    header('Content-Type: application/xml; charset=utf-8');
    echo $doc->saveXML();
    return true;
}

function handleWebDavGet($path, $head = false) {
    // 获取当前用户的文档
    $documents = loadJSON('data/documents.json');
    
    // 解码路径并移除开头和结尾的斜杠
    $path = trim(rawurldecode($path), '/');
    
    // 将路径分割成数组
    $pathParts = $path ? explode('/', $path) : [];
    
    // 找到当前路径对应的文件
    $currentFolderId = '0';
    $targetFile = null;
    
    // 遍历路径部分，查找目标文件
    foreach ($pathParts as $index => $part) {
        $found = false;
        foreach ($documents as $doc) {
            if ($doc['username'] === $_SESSION['username']) {
                $docName = $doc['type'] === 'folder' ? $doc['name'] : $doc['originalName'];
                
                if ($docName === $part && ($doc['parent'] ?? '0') === $currentFolderId) {
                    if ($index === count($pathParts) - 1) {
                        // 这是路径的最后一部分，应该是文件
                        $targetFile = $doc;
                    } else {
                        // 这是路径的中间部分，应该是文件夹
                        if ($doc['type'] !== 'folder') {
                            return false;
                        }
                        $currentFolderId = $doc['id'];
                    }
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            return false;
        }
    }
    
    // 如果找到了目标文件
    if ($targetFile) {
        if ($targetFile['type'] === 'folder') {
            return false; // 不支持下载文件夹
        }
        
        if ($head) {
            // 如果是 HEAD 请求，设置必要的头信息
            header('Content-Type: ' . mime_content_type('uploads/' . $targetFile['filename']));
            header('Content-Length: ' . $targetFile['filesize']);
            header('Last-Modified: ' . date('r', strtotime($targetFile['uploadDate'])));
            return true;
        }
        
        // 下载文件
        $filepath = 'uploads/' . $targetFile['filename'];
        if (file_exists($filepath)) {
            header('Content-Type: ' . mime_content_type($filepath));
            header('Content-Length: ' . filesize($filepath));
            header('Last-Modified: ' . date('r', strtotime($targetFile['uploadDate'])));
            $filename = $targetFile['originalName'];
            $encodedFilename = rawurlencode($filename);
            header('Content-Disposition: inline; filename="download"; filename*=UTF-8\'\'' . $encodedFilename);

            readfile($filepath);
            return true;
        }
    }
    
    return false;
}

function handleWebDavPut($path) {
    // 解码路径并移除开头和结尾的斜杠
    $path = trim(rawurldecode($path), '/');
    
    // 将路径分割成数组
    $pathParts = $path ? explode('/', $path) : [];
    
    // 找到当前路径对应的父文件夹ID
    $currentFolderId = '0';
    
    // 遍历路径部分，除了最后一个（文件名）
    for ($i = 0; $i < count($pathParts) - 1; $i++) {
        $part = $pathParts[$i];
        $found = false;
        $documents = loadJSON('data/documents.json');
        
        foreach ($documents as $doc) {
            if ($doc['username'] === $_SESSION['username'] && 
                $doc['type'] === 'folder' && 
                $doc['name'] === $part && 
                ($doc['parent'] ?? '0') === $currentFolderId) {
                $currentFolderId = $doc['id'];
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            // 如果文件夹不存在，创建它
            $result = createFolder($part, $currentFolderId);
            if ($result['success']) {
                $documents = loadJSON('data/documents.json'); // 重新加载以获取新ID
                foreach ($documents as $doc) {
                    if ($doc['username'] === $_SESSION['username'] && 
                        $doc['type'] === 'folder' && 
                        $doc['name'] === $part && 
                        ($doc['parent'] ?? '0') === $currentFolderId) {
                        $currentFolderId = $doc['id'];
                        break;
                    }
                }
            } else {
                return false;
            }
        }
    }
    
    // 保存文件内容
    $content = file_get_contents('php://input');
    $originalName = end($pathParts);
    $filename = uniqid() . '_' . $originalName;
    $filepath = 'uploads/' . $filename;
    
    if (file_put_contents($filepath, $content) === false) {
        return false;
    }
    
    // 保存文件记录
    $documents = loadJSON('data/documents.json');
    
    // 检查是否存在同名文件，如果存在则更新
    $fileUpdated = false;
    foreach ($documents as &$doc) {
        if ($doc['username'] === $_SESSION['username'] && 
            $doc['type'] === 'file' && 
            $doc['originalName'] === $originalName && 
            ($doc['parent'] ?? '0') === $currentFolderId) {
            // 删除旧文件
            if (file_exists('uploads/' . $doc['filename'])) {
                unlink('uploads/' . $doc['filename']);
            }
            // 更新记录
            $doc['filename'] = $filename;
            $doc['uploadDate'] = date('Y-m-d H:i:s');
            $doc['filesize'] = filesize($filepath);
            $fileUpdated = true;
            break;
        }
    }
    
    if (!$fileUpdated) {
        // 添加新文件记录
        $documents[] = [
            'id' => uniqid(),
            'type' => 'file',
            'filename' => $filename,
            'originalName' => $originalName,
            'username' => $_SESSION['username'],
            'uploadDate' => date('Y-m-d H:i:s'),
            'filesize' => filesize($filepath),
            'parent' => $currentFolderId
        ];
    }
    
    saveJSON('data/documents.json', $documents);
    return true;
}

function handleWebDavDelete($path) {
    // 解码路径并移除开头和结尾的斜杠
    $path = trim(rawurldecode($path), '/');
    
    // 将路径分割成数组
    $pathParts = $path ? explode('/', $path) : [];
    
    // 找到当前路径对应的文件或文件夹
    $currentFolderId = '0';
    $targetItem = null;
    
    // 遍历路径部分，查找目标项目
    foreach ($pathParts as $part) {
        $found = false;
        $documents = loadJSON('data/documents.json');
        
        foreach ($documents as $doc) {
            if ($doc['username'] === $_SESSION['username']) {
                $docName = $doc['type'] === 'folder' ? $doc['name'] : $doc['originalName'];
                
                if ($docName === $part && ($doc['parent'] ?? '0') === $currentFolderId) {
                    if ($part === end($pathParts)) {
                        $targetItem = $doc;
                    } else {
                        if ($doc['type'] !== 'folder') {
                            return false;
                        }
                        $currentFolderId = $doc['id'];
                    }
                    $found = true;
                    break;
                }
            }
        }
        
        if (!$found) {
            return false;
        }
    }
    
    if ($targetItem) {
        return deleteDocument($targetItem['id'], $targetItem['type'])['success'];
    }
    
    return false;
}

function handleMkcol($path) {
    return createFolder($path)['success'];
}

/***********************************************************
 * AJAX接口响应
 ***********************************************************/
if (isset($_GET['download'])) {
    ob_start(); // 开启输出缓冲
    $result = downloadFile($_GET['download']);
    if ($result === false) {
        ob_end_clean(); // 清除缓冲区
        header('Location: index.php');
        return;
    }
    ob_end_flush();
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if($action != 'login' && $action != 'register' && !authMiddleware())
        return;

    switch ($action) {
        case 'register':
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $res = registerUser($username, $password);
            jsonResponse([
                'success' => $res,
                'message' => $res ? '注册成功' : '用户名已存在'
            ]);
            return;

        case 'login':
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $res = loginUser($username, $password);
            jsonResponse([
                'success' => $res['success'],
                'message' => $res['success'] ? '登录成功' : '用户名或密码错误',
                'username' => $res['success'] ? $res['username'] : null
            ]);
            return;

        case 'logout':
            session_destroy();
            jsonResponse(['success' => true, 'message' => '已登出']);
            return;

        case 'upload':
            if (isset($_FILES['files'])) {
                $parent = $_POST['parent'] ?? '0';
                $result = uploadDocument($_FILES['files'], $parent);
                jsonResponse($result);
                return;
            }
            jsonResponse(['success' => false, 'message' => '未接收到文件']);
            return;

        case 'delete':
            $filename = $_POST['filename'] ?? '';
            $result = deleteDocument($filename);
            jsonResponse($result);
            return;

        case 'createFolder':
            $folderName = $_POST['folderName'] ?? '';
            $parent = $_POST['parent'] ?? '0';
            $result = createFolder($folderName, $parent);
            jsonResponse($result);
            return;

        case 'uploadChunk':
            $result = handleChunkUpload();
            jsonResponse($result);
            return;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'getDocuments':
            if(!authMiddleware()) return;

            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $folderId = $_GET['folderId'] ?? '0';
            $result = getDocuments($page, 10, $folderId);
            
            // 获取用户的WebDAV密钥
            $users = loadJSON('data/users.json');
            $webdavKey = '';
            foreach ($users as $user) {
                if ($user['username'] === $_SESSION['username']) {
                    $webdavKey = $user['webdav_key'];
                    break;
                }
            }
            
            jsonResponse([
                'success' => true,
                'data' => $result,
                'username' => $_SESSION['username'],
                'webdav_key' => $webdavKey
            ]);
            return;

        case 'preview':
            if(!authMiddleware()) return;
            
            $fileId = $_GET['id'] ?? '';
            $documents = loadJSON('data/documents.json');
            $fileInfo = null;
            
            // 验证文件所有权
            foreach ($documents as $doc) {
                if (isset($doc['id']) && 
                    $doc['id'] === $fileId && 
                    $doc['username'] === $_SESSION['username']) {
                    $fileInfo = $doc;
                    break;
                }
            }
            
            if (!$fileInfo) {
                header('HTTP/1.1 403 Forbidden');
                exit('Access denied');
            }
            
            $filepath = 'uploads/' . $fileInfo['filename'];
            if (!file_exists($filepath)) {
                header('HTTP/1.1 404 Not Found');
                exit('File not found');
            }
            
            // 获取文件扩展名
            $ext = strtolower(pathinfo($fileInfo['originalName'], PATHINFO_EXTENSION));
            
            // 设置适当的Content-Type
            $contentTypes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'bmp' => 'image/bmp',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'js' => 'text/javascript',
                'css' => 'text/css',
                'html' => 'text/html',
                'xml' => 'text/xml',
                'json' => 'application/json',
                'php' => 'text/plain',
                'md' => 'text/plain',
                'log' => 'text/plain',
                'ini' => 'text/plain',
                'conf' => 'text/plain',
                'sh' => 'text/plain',
                'bat' => 'text/plain'
            ];
            
            $contentType = $contentTypes[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            readfile($filepath);
            return;

        case 'search':
            if(!authMiddleware()) return;
            
            $keyword = $_GET['keyword'] ?? '';
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $result = searchDocuments($keyword, $page);
            jsonResponse([
                'success' => true,
                'data' => $result
            ]);
            return;
    }
}

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>SCloud</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- PDF.js -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf_viewer.min.css">
    <!-- CodeMirror -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
    
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            max-width: 900px;
        }
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            transition: all 0.3s;
        }
        .card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .list-group-item {
            border-left: none;
            border-right: none;
            transition: background-color 0.2s;
        }
        .list-group-item:hover {
            background-color: #f8f9fa;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            padding: 1rem 2rem;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 2px solid #0d6efd;
        }
        
        /* 预览模态框样式 */
        .preview-modal .modal-dialog {
            max-width: 90%;
            height: 90vh;
            margin: 1.75rem auto;
        }
        
        .preview-modal .modal-content {
            height: 100%;
        }
        
        .preview-modal .modal-body {
            height: calc(100% - 56px);
            padding: 0;
            overflow: hidden;
        }
        
        .preview-container {
            width: 100%;
            height: 100%;
            overflow: auto;
        }
        
        .preview-container img {
            max-width: 100%;
            height: auto;
        }
        
        #pdfViewer {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        .CodeMirror {
            height: 100%;
        }
        
        .file-icon-container {
            position: relative;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .file-icon-container .bi-eye {
            position: absolute;
            right: -4px;
            bottom: -4px;
            font-size: 0.7em;
            color: #0d6efd;
            background: transparent;
            border-radius: 50%;
            padding: 2px;
            filter: drop-shadow(0 0 1px white);
        }
    </style>
</head>
<body>

<div class="container pt-4 pb-5">
    <!-- 修改标题部分为左对齐添加logo -->
    <div class="d-flex align-items-center mb-4">
        <div class="me-3">
            <i class="bi bi-cloud-check text-primary" style="font-size: 2.2rem;"></i>
        </div>
        <div>
            <h3 class="mb-1" style="color: #2c3e50;">SCloud</h3>
            <p class="text-muted small mb-0">专业的企业文档存储与管理平台</p>
        </div>
    </div>

    <!-- 未登录状态：登录/注册界面 -->
    <div id="authSection" class="d-none">
        <div class="card p-4 mb-4">
            <ul class="nav nav-tabs border-0 mb-4" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#login-tab" type="button">登录</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#register-tab" type="button">注册</button>
                </li>
            </ul>
            <div class="tab-content">
                <!-- 登录表单 -->
                <div class="tab-pane fade show active" id="login-tab">
                    <form id="loginForm">
                        <div class="mb-3">
                            <label class="form-label">用户名</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                        </div>
                        <button class="btn btn-primary w-100 py-2" type="submit">登录</button>
                    </form>
                </div>
                
                <!-- 注册表单 -->
                <div class="tab-pane fade" id="register-tab">
                    <form id="registerForm">
                        <div class="mb-3">
                            <label class="form-label">用户名</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person-plus"></i></span>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                        </div>
                        <button class="btn btn-success w-100 py-2" type="submit">注册</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 已登录状态：主界面 -->
    <div id="mainSection" class="d-none">
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="dropdown">
                        <h5 class="mb-0 dropdown-toggle" 
                            style="cursor: pointer;" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false">
                            <i class="bi bi-person-circle me-2"></i>
                            <span id="welcomeText"></span>
                        </h5>
                        <ul class="dropdown-menu" onclick="event.stopPropagation();">
                            <li>
                                <div class="px-3 py-2" style="min-width: 400px;">
                                    <small class="text-muted">WebDAV访问信息：</small>
                                    <div class="mt-2">
                                        <div class="mb-2">
                                            <div class="d-flex align-items-center">
                                                <span class="me-2 text-nowrap">地址：</span>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control" id="webdavUrl" readonly>
                                                    <button class="btn btn-outline-secondary" type="button" id="copyWebDAVUrlBtn">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="d-flex align-items-center">
                                                <span class="me-2 text-nowrap">用户名：</span>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control" id="webdavUsername" readonly>
                                                    <button class="btn btn-outline-secondary" type="button" id="copyWebDAVUsernameBtn">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center">
                                                <span class="me-2 text-nowrap">密钥：</span>
                                                <div class="input-group input-group-sm">
                                                    <input type="password" class="form-control" id="webdavKey" readonly>
                                                    <button class="btn btn-outline-secondary" type="button" id="showOrHideWebDavKeyBtn">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline-secondary" type="button" id="copyWebDAVKeyBtn">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="bi bi-cloud-upload me-1"></i> 上传文档
                        </button>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                            <i class="bi bi-folder-plus me-1"></i> 新建文件夹
                        </button>
                        <button class="btn btn-outline-danger" id="logoutBtn">
                            <i class="bi bi-box-arrow-right me-1"></i> 登出
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb" id="breadcrumb">
                        <!-- 由 JavaScript 动态填充 -->
                    </ol>
                </nav>
                
                <div class="mb-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="searchInput" placeholder="搜索文档...">
                    </div>
                </div>

                <div id="documentList" class="list-group list-group-flush"></div>
                
                <nav class="mt-4">
                    <ul class="pagination justify-content-center" id="pagination"></ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- 上传模态框 -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>上传文件</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">选择文件</label>
                        <input type="file" name="files[]" multiple class="form-control" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-upload me-2"></i>开始上传
                    </button>
                </form>
                <div id="uploadProgressContainer" class="mt-3">
                    <div class="upload-progress d-none">
                        <div class="progress mb-2">
                            <div class="progress-bar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <div class="small text-muted filename-text"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加创建文件夹的模态框 -->
<div class="modal fade" id="createFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>新建文件夹</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createFolderForm">
                    <div class="mb-3">
                        <label class="form-label">文件夹名称</label>
                        <input type="text" name="folderName" class="form-control" required>
                    </div>
                    <button class="btn btn-success w-100" type="submit">
                        <i class="bi bi-check-lg me-2"></i>创建
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 文件预览模态框 -->
<div class="modal fade preview-modal" id="previewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">文件预览</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="preview-container">
                    <!-- 预览内容将动态插入这里 -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript代码部分持不变 -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/meta.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/markdown/markdown.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/shell/shell.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/sql/sql.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/yaml/yaml.min.js"></script>
<script>
$(function() {
    // 面加载时检查是否登录
    checkLoginStatus();

    // 登录
    $('#loginForm').on('submit', function(e) {
        e.preventDefault();
        $.post('', {
            action: 'login',
            username: this.username.value,
            password: this.password.value
        }, function(res) {
            if (res.success) {
                $('#welcomeText').text('欢迎，' + res.username + '!');
                $('#webdavUsername').text(res.username);
                $('#webdavKey').val(res.webdav_key);
                checkLoginStatus();
            } else {
                alert(res.message);
            }
        }, 'json');
    });

    // 注册
    $('#registerForm').on('submit', function(e) {
        e.preventDefault();
        $.post('', {
            action: 'register',
            username: this.username.value,
            password: this.password.value
        }, function(res) {
            alert(res.message);
            if (res.success) {
                // 注册成功后自切换到登录
                $('[data-bs-target="#login-tab"]').trigger('click');
            }
        }, 'json');
    });

    // 登出
    $('#logoutBtn').on('click', function() {
        $.post('', {action: 'logout'}, function(res) {
            if (res.success) {
                checkLoginStatus();
            }
        }, 'json');
    });

    // 修改搜索输入框的事件处理
    $('#searchInput').on('input', debounce(function() {
        const keyword = $(this).val().trim();
        if (keyword) {
            searchDocuments(keyword);
        } else {
            loadDocuments(1);
        }
    }, 500));

    // 添加抖函数
    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                func.apply(context, args);
            }, wait);
        };
    }

    // 修改搜索文档函数
    function searchDocuments(keyword, page = 1) {
        $.get('', {
            action: 'search',
            keyword: keyword,
            page: page,
            folderId: currentFolderId  // 添加当前文件夹ID
        }, function(res) {
            if (res.success) {
                const data = res.data;
                const docs = data.documents;
                const total = data.total;
                const totalPages = data.totalPages;
                const $list = $('#documentList');
                $list.empty();
                
                // 添加搜索结果提示
                $list.append(`
                    <div class="list-group-item bg-light">
                        <div class="row align-items-center">
                            <div class="col-8">
                                当前文件夹搜索结果: ${total} 个项目
                            </div>
                            <div class="col-4 text-end">
                                <button class="btn btn-outline-secondary btn-sm clear-search">
                                    <i class="bi bi-x-circle me-1"></i>清除搜索
                                </button>
                            </div>
                        </div>
                    </div>
                `);
                
                // 添加表头
                $list.append(`
                    <div class="list-group-item bg-light">
                        <div class="row align-items-center">
                            <div class="col-4">文件名</div>
                            <div class="col-3">创建时间</div>
                            <div class="col-2">大小</div>
                            <div class="col-3">操作</div>
                        </div>
                    </div>
                `);

                // 渲染文档列表（复用原有的文档渲染逻辑）
                docs.forEach(doc => {
                    $list.append(renderDocumentList(doc));
                });

                // 渲染分页
                renderSearchPagination(page, totalPages, keyword);
            }
        }, 'json');
    }

    // 渲染文档列表的函数
    function renderDocumentList(doc) {
        const isFolder = doc.type === 'folder';
        const icon = isFolder ? 'bi-folder' : 'bi-file-earmark';
        const name = isFolder ? doc.name : doc.originalName;
        const shortName = name.length > 20 ? name.substring(0, 20) + '...' : name;
        
        // 获取文件扩展名
        const ext = isFolder ? '' : name.split('.').pop().toLowerCase();
        
        // 判断文件是否可预览
        const previewable = !isFolder && isPreviewable(ext);
        
        return $(`
            <div class="list-group-item">
                <div class="row align-items-center">
                    <div class="col-4 text-truncate">
                        ${isFolder ? `
                            <span class="folder-name" 
                                data-folder-id="${doc.id}"
                                style="cursor: pointer;"
                                data-bs-toggle="tooltip" 
                                data-bs-placement="bottom" 
                                title="${name}">
                                <i class="bi bi-folder me-2"></i>
                                ${shortName}
                            </span>
                        ` : `
                            <span class="file-name" 
                                data-id="${doc.id}"
                                data-ext="${ext}"
                                style="cursor: pointer;"
                                data-bs-toggle="tooltip" 
                                data-bs-placement="bottom" 
                                title="${name}">
                                <span class="file-icon-container">
                                    <i class="bi bi-file-earmark"></i>
                                    ${previewable ? '<i class="bi bi-eye"></i>' : ''}
                                </span>
                                ${shortName}
                            </span>
                        `}
                    </div>
                    <div class="col-3">${formatDate(isFolder ? doc.createDate : doc.uploadDate)}</div>
                    <div class="col-2">${isFolder ? '-' : formatFileSize(doc.filesize)}</div>
                    <div class="col-3">
                        <div class="dropdown text-center">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" 
                                    type="button" 
                                    data-bs-toggle="dropdown" 
                                    aria-expanded="false">
                                操作
                            </button>
                            <ul class="dropdown-menu">
                                ${isFolder ? `
                                    <li>
                                        <button class="dropdown-item text-danger delete-item" 
                                                data-id="${doc.id}"
                                                data-type="${doc.type}">
                                            <i class="bi bi-trash me-2"></i>删除
                                        </button>
                                    </li>
                                ` : `
                                    <li>
                                        <a class="dropdown-item" 
                                        href="?download=${encodeURIComponent(doc.filename)}">
                                            <i class="bi bi-download me-2"></i>下载
                                        </a>
                                    </li>
                                    <li>
                                        <button class="dropdown-item text-danger delete-item" 
                                                data-id="${doc.id}"
                                                data-type="${doc.type}">
                                            <i class="bi bi-trash me-2"></i>删除
                                        </button>
                                    </li>
                                `}
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    // 添加搜索结果分页函数
    function renderSearchPagination(currentPage, totalPages, keyword) {
        const $pagination = $('#pagination');
        $pagination.empty();
        if (totalPages <= 1) return;

        const prevDisabled = currentPage <= 1 ? 'disabled' : '';
        const nextDisabled = currentPage >= totalPages ? 'disabled' : '';

        $pagination.append(`
            <li class="page-item ${prevDisabled}">
                <a class="page-link search-page" href="#" data-page="${currentPage - 1}" data-keyword="${keyword}">&laquo;</a>
            </li>
        `);
        
        for (let i = 1; i <= totalPages; i++) {
            const active = i === currentPage ? 'active' : '';
            $pagination.append(`
                <li class="page-item ${active}">
                    <a class="page-link search-page" href="#" data-page="${i}" data-keyword="${keyword}">${i}</a>
                </li>
            `);
        }

        $pagination.append(`
            <li class="page-item ${nextDisabled}">
                <a class="page-link search-page" href="#" data-page="${currentPage + 1}" data-keyword="${keyword}">&raquo;</a>
            </li>
        `);
    }

    // 修改搜索分页点击事件处理
    $(document).on('click', 'a.search-page', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        const keyword = $(this).data('keyword');
        if (page && keyword) {
            searchDocuments(keyword, page);
        }
    });

    // 添加清除搜索事件
    $(document).on('click', '.clear-search', function() {
        $('#searchInput').val('');
        loadDocuments(1);
    });

    // 添加分块上传相关常量和函数
    const CHUNK_SIZE = 2 * 1024 * 1024; // 2MB per chunk

    function uploadLargeFile(file, parent, onComplete) {
        const fileId = Date.now().toString();
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        let uploadedChunks = 0;
        
        // 创建进度条
        const progressContainer = $('#uploadProgressContainer');
        const progressTemplate = progressContainer.find('.upload-progress').first().clone();
        progressTemplate.removeClass('d-none');
        progressTemplate.find('.filename-text').text(`正在上传: ${file.name}`);
        progressContainer.append(progressTemplate);
        
        // 上传单个分块
        function uploadChunk(chunkNumber) {
            const start = chunkNumber * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            
            const formData = new FormData();
            formData.append('action', 'uploadChunk');
            formData.append('chunk', chunk);
            formData.append('chunkNumber', chunkNumber);
            formData.append('totalChunks', totalChunks);
            formData.append('originalName', file.name);
            formData.append('fileId', fileId);
            formData.append('parent', parent);
            
            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    if (res.success) {
                        uploadedChunks++;
                        const progress = Math.round((uploadedChunks / totalChunks) * 100);
                        progressTemplate.find('.progress-bar')
                            .css('width', progress + '%')
                            .text(progress + '%');
                        
                        if (res.isComplete) {
                            setTimeout(() => {
                                progressTemplate.fadeOut(() => {
                                    progressTemplate.remove();
                                });
                            }, 1000);
                            if (onComplete) onComplete(true, res.message);
                        } else if (chunkNumber + 1 < totalChunks) {
                            uploadChunk(chunkNumber + 1);
                        }
                    } else {
                        progressTemplate.remove();
                        if (onComplete) onComplete(false, res.message);
                    }
                },
                error: function() {
                    progressTemplate.remove();
                    if (onComplete) onComplete(false, '上传出错');
                }
            });
        }
        
        // 开始上传第一个分块
        uploadChunk(0);
    }

    // 修改上传表单提交处理
    $('#uploadForm').on('submit', function(e) {
        e.preventDefault();
        const files = this.querySelector('input[type="file"]').files;
        
        // 清空进度条容器，但保留模板
        const progressTemplate = $('#uploadProgressContainer .upload-progress').first();
        $('#uploadProgressContainer').empty().append(progressTemplate);
        
        // 添加计数器来跟踪上传完成的文件数和成功数
        let completedUploads = 0;
        let successfulUploads = 0;
        const totalFiles = files.length;
        const uploadResults = [];
        
        // 检查是否所有文件都上传完成
        function checkAllUploadsComplete() {
            completedUploads++;
            if (completedUploads === totalFiles) {
                setTimeout(() => {
                    // 显示汇总信息
                    if (uploadResults.length > 0) {
                        const successCount = uploadResults.filter(r => r.success).length;
                        const failCount = uploadResults.length - successCount;
                        let message = `上传完成：${successCount}个成功`;
                        if (failCount > 0) {
                            message += `，${failCount}个失败\n`;
                            // 添加失败详情
                            const failDetails = uploadResults
                                .filter(r => !r.success)
                                .map(r => `${r.filename}: ${r.message}`)
                                .join('\n');
                            message += failDetails;
                        }
                        alert(message);
                    }
                    $('#uploadModal').modal('hide');
                    $('#uploadForm')[0].reset(); // 重置表单
                    loadDocuments(); // 刷新文件列表
                }, 1500);
            }
        }
        
        Array.from(files).forEach(file => {
            if (file.size > CHUNK_SIZE) {
                // 大文件使用分块上传
                uploadLargeFile(file, currentFolderId, (success, message) => {
                    uploadResults.push({
                        filename: file.name,
                        success: success,
                        message: message
                    });
                    checkAllUploadsComplete();
                });
            } else {
                // 小文件使用原来的上传方式，但添加进度显示
                const formData = new FormData();
                formData.append('action', 'upload');
                formData.append('files[]', file);
                formData.append('parent', currentFolderId);
                
                // 创建进度条
                const progressTemplate = $('#uploadProgressContainer .upload-progress').first().clone();
                progressTemplate.removeClass('d-none');
                progressTemplate.find('.filename-text').text(`正在上传: ${file.name}`);
                $('#uploadProgressContainer').append(progressTemplate);
                
                $.ajax({
                    url: '',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function() {
                        const xhr = new window.XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(e) {
                            if (e.lengthComputable) {
                                const progress = Math.round((e.loaded / e.total) * 100);
                                progressTemplate.find('.progress-bar')
                                    .css('width', progress + '%')
                                    .text(progress + '%');
                            }
                        }, false);
                        return xhr;
                    },
                    success: function(res) {
                        if (res.success) {
                            setTimeout(() => {
                                progressTemplate.fadeOut(() => {
                                    progressTemplate.remove();
                                });
                            }, 1000);
                            uploadResults.push({
                                filename: file.name,
                                success: true,
                                message: res.message
                            });
                        } else {
                            progressTemplate.remove();
                            uploadResults.push({
                                filename: file.name,
                                success: false,
                                message: res.message
                            });
                        }
                        checkAllUploadsComplete();
                    },
                    error: function() {
                        progressTemplate.remove();
                        uploadResults.push({
                            filename: file.name,
                            success: false,
                            message: '上传出错'
                        });
                        checkAllUploadsComplete();
                    }
                });
            }
        });
    });

    // 检查登录状态
    function checkLoginStatus() {
        $.ajax({
            url: '',
            type: 'GET',
            data: {action: 'getDocuments'},
            dataType: 'json',
            success: function(res) {
                if (!res.success && res.message === '请先登录') {
                    $('#authSection').removeClass('d-none');
                    $('#mainSection').addClass('d-none');
                } else {
                    $('#authSection').addClass('d-none');
                    $('#mainSection').removeClass('d-none');
                    $('#welcomeText').text('欢迎，' + res.username + '!');
                    $('#webdavUsername').val(res.username);  // 修改这里
                    if (res.webdav_key) {
                        $('#webdavKey').val(res.webdav_key);
                    }
                    setWebDAVUrl();
                    loadDocuments();
                }
            }
        });
    }

    // 在 script 标签内添加全局变量
    let currentFolderId = '0';

    // 修改 loadDocuments 函数
    function loadDocuments(page = 1) {
        // 销毁所有现有的 tooltips
        $('[data-bs-toggle="tooltip"]').tooltip('dispose');
        
        $.get('', {
            action: 'getDocuments',
            page: page,
            folderId: currentFolderId
        }, function(res) {
            if (res.success) {
                const data = res.data;
                // 渲染面包屑
                renderBreadcrumb(data.breadcrumb);
                const docs = data.documents;
                const total = data.total;
                const totalPages = data.totalPages;
                const $list = $('#documentList');
                $list.empty();
                
                // 添加表格
                $list.append(`
                    <div class="list-group-item bg-light">
                        <div class="row align-items-center">
                            <div class="col-4">文件名</div>
                            <div class="col-3">创建时间</div>
                            <div class="col-2">大小</div>
                            <div class="col-3">操作</div>
                        </div>
                    </div>
                `);

                docs.forEach(doc => {
                    $list.append(renderDocumentList(doc));
                });
                renderPagination(page, totalPages);

                // 初始化所有tooltips
                $('[data-bs-toggle="tooltip"]').tooltip();
            }
        }, 'json');
    }

    // 渲染页
    function renderPagination(currentPage, totalPages) {
        const $pagination = $('#pagination');
        $pagination.empty();
        if (totalPages <= 1) return;

        const prevDisabled = currentPage <= 1 ? 'disabled' : '';
        const nextDisabled = currentPage >= totalPages ? 'disabled' : '';

        $pagination.append('<li class="page-item ' + prevDisabled + '"><a class="page-link" href="#" data-page="' + (currentPage - 1) + '">&laquo;</a></li>');
        
        for (let i = 1; i <= totalPages; i++) {
            const active = i === currentPage ? 'active' : '';
            $pagination.append('<li class="page-item ' + active + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>');
        }

        $pagination.append('<li class="page-item ' + nextDisabled + '"><a class="page-link" href="#" data-page="' + (currentPage + 1) + '">&raquo;</a></li>');

        $pagination.find('a.page-link').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page && page > 0 && page <= totalPages) {
                loadDocuments(page);
            }
        });
    }

    // 添加辅助函数用于格式化文件大小
    function formatFileSize(bytes) {
        if (bytes === undefined || bytes === null) return '-';
        if (bytes === 0) return '0 B';
        try {
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        } catch (e) {
            return '-';
        }
    }

    // 添加辅助函数用于格式日期
    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // 删除文档
    $(document).on('click', '.delete-item', function() {
        if (!confirm('确定要删除个文件？此操作不可恢复。')) {
            return;
        }
        
        const id = $(this).data('id');
        const type = $(this).data('type');
        $.post('', {
            action: 'delete',
            filename: id,
            type: type
        }, function(res) {
            alert(res.message);
            if (res.success) {
                loadDocuments();
            }
        }, 'json');
    });

    // 创建文件夹
    $('#createFolderForm').on('submit', function(e) {
        e.preventDefault();
        const folderName = this.folderName.value.trim();
        
        if (!folderName) {
            alert('请输入文件夹名称');
            return;
        }
        
        $.post('', {
            action: 'createFolder',
            folderName: folderName,
            parent: currentFolderId
        }, function(res) {
            alert(res.message);
            if (res.success) {
                $('#createFolderModal').modal('hide');
                loadDocuments();
            }
        }, 'json');
    });

    // 添加面包屑渲染函数
    function renderBreadcrumb(breadcrumb) {
        const $breadcrumb = $('#breadcrumb');
        $breadcrumb.empty();
        
        breadcrumb.forEach((item, index) => {
            const isLast = index === breadcrumb.length - 1;
            const $item = $(`
                <li class="breadcrumb-item ${isLast ? 'active' : ''}">
                    ${isLast ? `
                        ${item.name}
                    ` : `
                        <a href="#" data-folder-id="${item.id}">${item.name}</a>
                    `}
                </li>
            `);
            $breadcrumb.append($item);
        });
    }

    // 添加文件夹点击事件处理
    $(document).on('click', '.folder-name', function() {
        // 销毁当前元素的 tooltip
        $(this).tooltip('dispose');
        
        currentFolderId = $(this).data('folder-id');
        loadDocuments(1);
    });

    // 添加面包屑导航点击事件
    $(document).on('click', '#breadcrumb a', function(e) {
        e.preventDefault();
        // 销毁所有 tooltips
        $('[data-bs-toggle="tooltip"]').tooltip('dispose');
        
        currentFolderId = $(this).data('folder-id');
        loadDocuments(1);
    });

    // 在 script 标签内添加预览相关函数
    // 判断文件是否可预览
    function isPreviewable(ext) {
        const previewableExts = [
            // 图片
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp',
            // PDF
            'pdf',
            // 文本和代码
            'txt', 'js', 'css', 'html', 'xml', 'json', 'php', 'md',
            // 其他文本文件
            'log', 'ini', 'conf', 'sh', 'bat'
        ];
        return previewableExts.includes(ext.toLowerCase());
    }

    // 修改预览处理函数
    $(document).on('click', '.file-name', function() {
        const fileId = $(this).data('id');
        const ext = $(this).data('ext').toLowerCase();
        if (isPreviewable(ext)) {
            const $previewContainer = $('#previewModal .preview-container');
            
            // 清空预览容器
            $previewContainer.empty();
            
            let editor = null;
            
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext)) {
                $previewContainer.html(`<img src="?action=preview&id=${encodeURIComponent(fileId)}" alt="预览图片">`);
            } else if (ext === 'pdf') {
                $previewContainer.html(`<iframe id="pdfViewer" src="?action=preview&id=${encodeURIComponent(fileId)}"></iframe>`);
            } else {
                $.ajax({
                    url: `?action=preview&id=${encodeURIComponent(fileId)}`,
                    type: 'GET',
                    dataType: 'text',
                    success: function(content) {
                        const $editor = $('<textarea>').val(content);
                        $previewContainer.append($editor);
                        
                        editor = CodeMirror.fromTextArea($editor[0], {
                            theme: 'monokai',
                            lineNumbers: true,
                            readOnly: true,
                            lineWrapping: true
                        });
                    }
                });
            }
            
            const $modal = $('#previewModal');
            $modal.modal('show');
            
            $modal.on('shown.bs.modal', function() {
                if (editor) {
                    editor.refresh();
                }
            });
        }
    });

    // 设置 WebDAV URL
    function setWebDAVUrl() {
        const currentUrl = window.location.origin;
        $('#webdavUrl').val(currentUrl + '/webdav/');
    }
    
    // 在检查登录状态成功后调用
    function checkLoginStatus() {
        $.ajax({
            url: '',
            type: 'GET',
            data: {action: 'getDocuments'},
            dataType: 'json',
            success: function(res) {
                if (!res.success && res.message === '请先登录') {
                    $('#authSection').removeClass('d-none');
                    $('#mainSection').addClass('d-none');
                } else {
                    $('#authSection').addClass('d-none');
                    $('#mainSection').removeClass('d-none');
                    $('#welcomeText').text('欢迎，' + res.username + '!');
                    $('#webdavUsername').val(res.username);
                    if (res.webdav_key) {
                        $('#webdavKey').val(res.webdav_key);
                    }
                    setWebDAVUrl();
                    loadDocuments();
                }
            }
        });
    }
    
    // 防止下拉菜单点击内部时关闭
    $('.dropdown-menu').on('click', function(e) {
        e.stopPropagation();
    });

    // 添加通用复制文本函数
    function copyText(elementId) {
        const input = document.getElementById(elementId);
        input.select();
        document.execCommand('copy');
        
        // 显示复制成功提示
        const button = event.currentTarget;
        const originalHtml = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check"></i>';
        setTimeout(() => {
            button.innerHTML = originalHtml;
        }, 1000);
    }

    $('#copyWebDAVUsernameBtn').on('click', function() {
        copyText('webdavUsername');
    });

    $('#copyWebDAVUrlBtn').on('click', function() {
        copyText('webdavUrl');
    });

    $('#copyWebDAVKeyBtn').on('click', function() {
        const input = document.getElementById('webdavKey');
        const button = document.getElementById('showOrHideWebDavKeyBtn');
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');

            copyText('webdavKey');

            input.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
        else
        {
            copyText('webdavKey');
        }
    });

    $('#showOrHideWebDavKeyBtn').on('click', function() {
        const input = document.getElementById('webdavKey');
        const button = document.getElementById('showOrHideWebDavKeyBtn');
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    });
});
</script>
</body>
</html>
