<?php

use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;

Theme::listen(ThemeEvents::WEB_MIDDLEWARE_BEFORE, function ($request) {

    // ============================================================
    // Guard 1: 超大數字 query param 攻擊防護
    // 要新增保護對象，只需在 $guardedNumericParams 陣列加一個字串即可。
    // ============================================================
    $guardedNumericParams = [
        'page',
        'uploaded_to',
    ];
    foreach ($guardedNumericParams as $param) {
        $value = $request->query($param);
        if (is_string($value) && preg_match('/^-?\d{10,}$/', $value)) {
            return response()->view('errors.404', [], 404);
        }
    }

    // ============================================================
    // Guard 2: 陣列注入防護（scalar 參數不得為 array）
    //
    // 攻擊手法：將 sort=xxx 改成 sort[]=xxx，後端 PHP 收到 array。
    // 後端未做型別檢查，直接傳入 substr() / parse_url() / orderBy()
    // 等函式，在 PHP 8 會拋出 TypeError → 未捕捉 500 Internal Server Error。
    //
    // 受影響端點（涵蓋報告中全部 27 個問題）：
    //   - PATCH /preferences/change-sort/{type}  → sort, order, _return
    //   - PATCH /preferences/change-view/{type}  → view, _return
    //   - PATCH /preferences/toggle-dark-mode    → _return
    //   - GET   /shelves, /books, /tags           → search, sort, _return
    //   - GET   /search                           → term
    //   - GET   /settings/audit                   → sort, order, date_from, date_to, user, type
    //   - PATCH /users/{id}/avatar                → profile_image（見 Guard 4）
    // ============================================================
    $scalarOnlyParams = [
        'sort',      // 列表欄位排序
        'order',     // asc / desc
        'view',      // grid / list
        'search',    // 清單頁快速篩選
        'term',      // 全域搜尋關鍵字
        '_return',   // 操作後重導向目標
        'expand',    // 區塊展開狀態
        'date_from', // 稽核記錄起始日
        'date_to',   // 稽核記錄結束日
        'user',      // 稽核記錄使用者篩選
        'type',      // 稽核記錄事件類型篩選
        'permission',// 搜尋頁權限篩選
        'extras',    // 搜尋進階選項（scalar，不得為 array）
        // my-account/profile 欄位（提交為 array 會導致 session flash 寫入 array，
        // 下一個 GET 渲染時 old() 回傳 array → Blade htmlspecialchars 炸 500）
        'name',      // 使用者顯示名稱
        'email',     // 電子郵件
        'language',  // 語言設定
        'password',  // 密碼欄位
        'password_confirmation',
    ];
    foreach ($scalarOnlyParams as $param) {
        // input() 同時涵蓋 GET query string 與 POST body
        if (is_array($request->input($param))) {
            return response()->view('errors.404', [], 404);
        }
    }

    // ============================================================
    // Guard 3: 搜尋進階參數的子元素不得再是陣列（僅限 search 路徑）
    //
    // 正常搜尋：exact[]=phrase、tags[]=keyword、filters[updated_by]=1
    // 攻擊請求：exact[0][]=... / tags[0][]=... / filters[key][]=...
    // → SearchOptions::fromRequest() 迭代元素時傳入 array 給
    //   SearchOption 建構子 → TypeError → 500
    //
    // 注意：頁面編輯時 tags 格式為 tags[0][name]=xxx&tags[0][value]=yyy
    // （元素是 associative array），與搜尋攻擊格式相同，因此只在
    // search 路徑啟用，避免誤擋合法的 tag 編輯操作。
    // ============================================================
    if (str_contains($request->path(), 'search')) {
        foreach (['exact', 'tags'] as $param) {
            $values = $request->input($param);
            if (is_array($values)) {
                foreach ($values as $item) {
                    if (is_array($item)) {
                        return response()->view('errors.404', [], 404);
                    }
                }
            }
        }

        $filters = $request->input('filters');
        if (is_array($filters)) {
            foreach ($filters as $filterVal) {
                if (is_array($filterVal)) {
                    return response()->view('errors.404', [], 404);
                }
            }
        }
    }

    // ============================================================
    // Guard 4: 檔案上傳欄位不得為陣列（profile_image[] 注入防護）
    //
    // 攻擊手法：multipart 表單中將 profile_image 改成 profile_image[]
    // → $request->file('profile_image') 回傳 array of UploadedFile
    // → 後端驗證規則（mimes、max）無法套用到陣列 → 例外 → 500
    // ============================================================
    $singleFileFields = ['profile_image', 'file'];  // 'file' = 附件上傳欄位 (AttachmentController)
    foreach ($singleFileFields as $field) {
        if (is_array($request->files->get($field))) {
            return response()->view('errors.404', [], 404);
        }
    }

    // ============================================================
    // Guard 5: 禁止 user 自行刪除帳號 (BookStack: DELETE /my-account)
    // ============================================================
    if ($request->isMethod('DELETE') &&
        str_contains($request->path(), 'my-account')) {
        $ex = new \Symfony\Component\HttpKernel\Exception\HttpException(
            403, '系統禁止自行刪除帳號，請聯繫管理員。'
        );
        return response()->view('errors.403', ['exception' => $ex], 403);
    }

    return null;
});
