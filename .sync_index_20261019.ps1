$source = 'D:\work\beikeshop'
$target = 'D:\work\beikeshop-prod-a'
$tag = '20261019'
$reportNames = @(
    "SYNC_DEV_TO_PROD_A_${tag}.json",
    "SYNC_DEV_TO_PROD_A_${tag}.md",
    "SYNC_DEV_TO_PROD_A_${tag}_FILES.md",
    "SYNC_DEV_TO_PROD_A_${tag}_AUDIT.md"
)
$excludeDirs = @('database', 'public', 'storage', 'vendor', 'node_modules', 'dist', 'mmdb', '.git', '.claude', '.idea')

function Get-IndexedFiles([string] $base) {
    Get-ChildItem -LiteralPath $base -File -Recurse -Force |
        Where-Object {
            $rel = $_.FullName.Substring($base.Length + 1)
            $normalizedRel = $rel.Replace('\', '/')
            $parts = $rel -split '[\\/]'
            if ($parts | Where-Object { $excludeDirs -contains $_ }) { return $false }
            if ($rel -eq '.sync_index_20261019.ps1') { return $false }
            if ($rel -match '(^|[\\/])\.env(\..*)?$') { return $false }
            if ($rel -match '(^|[\\/])Static([\\/]|$)') { return $false }
            if ($normalizedRel -like "docs/SYNC_DEV_TO_PROD_A_${tag}*") { return $false }
            return $true
        } |
        ForEach-Object { $_.FullName.Substring($base.Length + 1).Replace('\', '/') } |
        Sort-Object
}

$sourceFiles = @(Get-IndexedFiles $source)
$targetFiles = @(Get-IndexedFiles $target)
$sourceSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
$targetSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
$sourceFiles | ForEach-Object { [void] $sourceSet.Add($_) }
$targetFiles | ForEach-Object { [void] $targetSet.Add($_) }
$missing = @($sourceFiles | Where-Object { -not $targetSet.Contains($_) })
$extra = @($targetFiles | Where-Object { -not $sourceSet.Contains($_) })
$mismatch = [Collections.Generic.List[object]]::new()

foreach ($path in $sourceFiles) {
    if (-not $targetSet.Contains($path)) { continue }
    $sourceHash = (Get-FileHash -LiteralPath (Join-Path $source $path) -Algorithm SHA256).Hash.ToLowerInvariant()
    $targetHash = (Get-FileHash -LiteralPath (Join-Path $target $path) -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($sourceHash -ne $targetHash) {
        [void] $mismatch.Add([pscustomobject]@{
            path = $path
            source_sha256 = $sourceHash
            target_sha256 = $targetHash
        })
    }
}

$records = [Collections.Generic.List[object]]::new()
foreach ($path in $sourceFiles) {
    $file = Get-Item -LiteralPath (Join-Path $source $path)
    $hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    [void] $records.Add([pscustomobject]@{
        path = $path
        bytes = [int64] $file.Length
        sha256 = $hash
    })
}

$directoryCounts = [ordered] @{}
foreach ($record in $records) {
    $top = ($record.path -split '/')[0]
    if (-not $directoryCounts.Contains($top)) { $directoryCounts[$top] = 0 }
    $directoryCounts[$top]++
}

$changedFiles = @(
    [pscustomobject]@{ path = 'beike/Admin/Services/OrderWordExportService.php'; action = '新增' }
    [pscustomobject]@{ path = 'tests/Unit/Order/GuestOrderClaimTest.php'; action = '新增' }
    [pscustomobject]@{ path = 'tests/Unit/Order/OrderWordExportCategoryTest.php'; action = '新增' }
    [pscustomobject]@{ path = '.phpunit.result.cache'; action = '更新' }
    [pscustomobject]@{ path = 'beike/Admin/Http/Controllers/OrderController.php'; action = '更新' }
    [pscustomobject]@{ path = 'beike/Admin/Routes/admin.php'; action = '更新' }
    [pscustomobject]@{ path = 'lang/en/admin/order.php'; action = '更新' }
    [pscustomobject]@{ path = 'lang/zh_cn/admin/order.php'; action = '更新' }
    [pscustomobject]@{ path = 'lang/zh_hk/admin/order.php'; action = '更新' }
    [pscustomobject]@{ path = 'resources/beike/admin/views/pages/orders/form.blade.php'; action = '更新' }
)

$data = [ordered] @{
    date = '2026-10-19'
    source = $source
    target = $target
    boundary = [ordered] @{
        included = 'Laravel、BeikeShop 核心、插件 PHP/路由/视图/迁移、语言包、配置、主题、测试和文档代码'
        excluded = @('database/', 'public/', 'storage/', 'vendor/', 'node_modules/', 'dist/', 'mmdb/', '.git/', '.claude/', '.idea/', '所有插件 Static/', '所有 .env/.env.*', '本次同步报告文件自身')
    }
    pre_sync = [ordered] @{
        source_file_count = 2611
        target_file_count = 2608
        missing = @('beike/Admin/Services/OrderWordExportService.php', 'tests/Unit/Order/GuestOrderClaimTest.php', 'tests/Unit/Order/OrderWordExportCategoryTest.php')
        extra = @()
        mismatch = @('.phpunit.result.cache', 'beike/Admin/Http/Controllers/OrderController.php', 'beike/Admin/Routes/admin.php', 'lang/en/admin/order.php', 'lang/zh_cn/admin/order.php', 'lang/zh_hk/admin/order.php', 'resources/beike/admin/views/pages/orders/form.blade.php')
    }
    post_sync = [ordered] @{
        source_file_count = $records.Count
        target_file_count = $targetFiles.Count
        missing = $missing
        extra = $extra
        mismatch = $mismatch
    }
    changed_files = $changedFiles
    directory_counts = $directoryCounts
    files = $records
}

$encoding = [Text.UTF8Encoding]::new($false)
$jsonPath = Join-Path $source "docs/SYNC_DEV_TO_PROD_A_${tag}.json"
$summaryPath = Join-Path $source "docs/SYNC_DEV_TO_PROD_A_${tag}.md"
$filesPath = Join-Path $source "docs/SYNC_DEV_TO_PROD_A_${tag}_FILES.md"
$auditPath = Join-Path $source "docs/SYNC_DEV_TO_PROD_A_${tag}_AUDIT.md"

$summaryRows = ($changedFiles | ForEach-Object { "| $($_.action) | ``$($_.path)`` |" }) -join "`n"
$summary = @"
# 开发版同步到 prod-a 代码记录

同步日期：2026-10-19

代码源：D:/work/beikeshop

统一代码预览目录：D:/work/beikeshop-prod-a

## 同步结论

本次按 2026-10-13 文档既定边界完成开发版到 `prod-a` 的代码同步。变更前发现 3 个缺失文件、7 个同路径内容不一致文件，已覆盖完成。同步后纳入索引的 $($records.Count) 个文件，缺失 $($missing.Count)，多余 $($extra.Count)，SHA-256 不一致 $($mismatch.Count)。

本次变更共 10 个文件：新增 3 个，更新 7 个。完整逐文件 SHA-256 索引见 [同步文件索引](./SYNC_DEV_TO_PROD_A_${tag}_FILES.md)，结构化记录见 [JSON 索引](./SYNC_DEV_TO_PROD_A_${tag}.json)。

## 边界

保留 prod-a 原有 .env、数据库、public/、storage/、vendor/、node_modules/、dist/、mmdb/ 和插件 Static/ 静态资源；这些内容未参与覆盖。同步报告文件复制到两端，但不纳入自身 SHA-256 索引。

## 变更文件

| 操作 | 文件 |
| --- | --- |
$summaryRows

## 使用说明

prod-a 作为测试环境的统一代码候选版本使用时，应准备独立 .env、测试数据库、缓存/队列、搜索服务和上传目录，并在目标 PHP/Composer 环境重新执行依赖校验、迁移和 HTTP/后台/结账验收。
"@

$audit = @"
# 开发版与 prod-a 同步审计

审计日期：2026-10-19

## 结果

- 同步前索引文件：开发版 2611 个，prod-a 2608 个。
- 同步前差异：缺失 3 个、目标多余 0 个、哈希不一致 7 个。
- 同步后索引文件：开发版 $($records.Count) 个，prod-a $($targetFiles.Count) 个。
- 同步后差异：缺失 $($missing.Count) 个、目标多余 $($extra.Count) 个、哈希不一致 $($mismatch.Count) 个。
- 目录级计数及每个文件的 SHA-256 在 JSON/FILES 索引中保存。

## 差异文件

### 新增

- beike/Admin/Services/OrderWordExportService.php
- tests/Unit/Order/GuestOrderClaimTest.php
- tests/Unit/Order/OrderWordExportCategoryTest.php

### 更新

- .phpunit.result.cache
- beike/Admin/Http/Controllers/OrderController.php
- beike/Admin/Routes/admin.php
- lang/en/admin/order.php
- lang/zh_cn/admin/order.php
- lang/zh_hk/admin/order.php
- resources/beike/admin/views/pages/orders/form.blade.php

## 验证边界

本次执行了逐文件 SHA-256 对比和路径集合对比，结果为零差异。未执行数据库迁移、缓存清理、队列重启、PHP-FPM/容器重启或浏览器静态资源构建；因此该目录是代码同步完成的测试候选版本，不等同于运行时验收完成。
"@

$filesBuilder = [Text.StringBuilder]::new()
[void] $filesBuilder.AppendLine('# 同步文件 SHA-256 索引（2026-10-19）')
[void] $filesBuilder.AppendLine('')
[void] $filesBuilder.AppendLine("范围：共 $($records.Count) 个文件；排除运行时、依赖、环境文件、插件 Static/ 和本次同步报告自身。")
[void] $filesBuilder.AppendLine('')
[void] $filesBuilder.AppendLine('| 文件 | 字节数 | SHA-256 |')
[void] $filesBuilder.AppendLine('| --- | ---: | --- |')
foreach ($record in $records) {
    [void] $filesBuilder.AppendLine("| ``$($record.path)`` | $($record.bytes) | $($record.sha256) |")
}

[IO.File]::WriteAllText($jsonPath, ($data | ConvertTo-Json -Depth 8), $encoding)
[IO.File]::WriteAllText($summaryPath, $summary.TrimStart(), $encoding)
[IO.File]::WriteAllText($auditPath, $audit.TrimStart(), $encoding)
[IO.File]::WriteAllText($filesPath, $filesBuilder.ToString(), $encoding)

foreach ($name in $reportNames) {
    Copy-Item -LiteralPath (Join-Path $source "docs/$name") -Destination (Join-Path $target "docs/$name") -Force
}

Write-Output "INDEX_WRITTEN records=$($records.Count) reports=$($reportNames.Count) post_missing=$($missing.Count) post_extra=$($extra.Count) post_mismatch=$($mismatch.Count)"
