<?php

use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;
use BookStack\Theming\ThemeViews;
use Illuminate\Routing\Router;

include_once 'AiChatSettings.php';
include_once 'drivers/DriverInterface.php';
include_once 'drivers/ParsesOpenAiStream.php';

// 自動載入 drivers/ 下所有 *Driver.php，新增 driver 只需放入此目錄
foreach (glob(__DIR__ . '/drivers/*Driver.php') as $file) {
    include_once $file;
}

include_once 'AiChatApi.php';
include_once 'BookStackSearcher.php';
include_once 'AiChatController.php';

Theme::listen(ThemeEvents::THEME_REGISTER_VIEWS, function (ThemeViews $themeViews) {
    view()->getFinder()->prependLocation(__DIR__ . '/views');
    $themeViews->renderAfter('layouts.parts.header-links-start', 'ai-chat-header-button');
    $themeViews->renderAfter('layouts.parts.base-body-end', 'ai-chat-sidebar');
});

Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (Router $router) {
    $router->get('/ai-chat',          [AiChatController::class, 'show']);
    $router->get('/ai-chat/ask',      [AiChatController::class, 'ask']);
    $router->get('/ai-chat/settings', [AiChatController::class, 'showSettings']);
    $router->post('/ai-chat/settings',[AiChatController::class, 'saveSettings']);
});
