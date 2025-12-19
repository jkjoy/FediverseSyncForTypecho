# Fediverse Sync for Typecho

这是一个Typecho插件，可以将你的Typecho博客文章自动同步发布到Fediverse网络中的各个实例，包括Mastodon、GoToSocial和Misskey。

## 功能特点

- 发布新文章时自动同步到Fediverse实例
- 支持多种Fediverse实例：
  - Mastodon
  - GoToSocial
  - Misskey
- **自定义同步内容模板**（新功能）
  - 支持变量：{title}、{permalink}、{content}、{author}、{created}、{site_name}
  - 可自定义同步消息的格式和内容
- **通过模板控制是否包含原文内容**
  - 模板包含 `{content}` 才会替换并包含文章正文
  - 可设置原文内容长度限制，自动去除HTML标签并格式化
- 简洁的消息格式
- 支持多种可见性设置
- 调试模式支持
- 手动从后台面板同步文章
- 后台配置信息预览

## 安装方法

1. 下载本插件
2. 将插件文件夹上传到Typecho的`/usr/plugins/`目录下，并确保文件夹名为`FediverseSync`
3. 登录Typecho后台，进入"插件"页面，启用"Fediverse Sync"插件
4. 进入插件设置页面配置你的Fediverse实例信息

## 配置说明

### 基本配置

- **实例类型**：选择你使用的Fediverse实例类型（Mastodon/GoToSocial/Misskey）
- **实例地址**：填写你的实例URL，如`https://mastodon.social`
- **Access Token**：访问令牌（不同实例获取方式不同）
- **文章可见性**：发布到Fediverse时的可见性级别
- **原文内容长度限制**：当模板包含 `{content}` 时生效，限制显示的字数（0表示不限制）
- **同步内容模板**：自定义同步消息的格式，支持以下变量：
  - `{title}` - 文章标题
  - `{permalink}` - 文章链接
  - `{content}` - 文章内容（模板包含才会显示）
  - `{author}` - 作者名称
  - `{created}` - 发布时间
  - `{site_name}` - 站点名称
- **调试模式**：是否启用详细日志
- **API超时**：API请求超时时间（秒）

### 获取Access Token

#### Mastodon/GoToSocial
1. 登录到你的Mastodon/GoToSocial实例
2. 进入设置 > 开发 > 新建应用
3. 填写应用信息，确保勾选`write:statuses`权限
4. 创建后获取访问令牌

#### Misskey
1. 登录到你的Misskey实例
2. 进入设置 > API > 创建应用
3. 填写应用信息，确保勾选适当的权限（notes的读写权限）
4. 创建后获取访问令牌

## 使用方法

### 自动同步

启用插件并配置好后，当你发布新文章时，插件会自动将文章同步到你配置的Fediverse实例。

默认消息格式为：
```
「文章标题」
文章链接
From「网站名称」
```

你可以通过**同步内容模板**自定义这个消息格式，例如：
- 包含原文内容：`「{title}」\n\n{content}\n\n{permalink}\n\n作者：{author} | 发布时间：{created}`
- 简洁格式：`新文章：{title} {permalink}`
- 详细格式：`📢 {site_name} 发布了新文章「{title}」\n\n{content}\n\n👉 {permalink}\n\n作者：{author} | {created}`

### 手动同步

进入Typecho后台 > Fediverse同步面板，你可以：
1. 查看当前同步配置信息
2. 查看所有文章的同步状态和内容预览
3. 选择一篇或多篇文章手动同步

## 日志说明

- 插件日志写入文件：`/usr/logs/fediverse-sync.log`（位于 Typecho 根目录下的 `usr/logs`）
- 错误日志（`status=error`）会写入文件；启用“调试模式”后会记录更多调试信息

## 版本历史
- **1.6.4**: `{content}` 使用文章原始 Markdown 文本（保留换行）；修复标题/作者可能重复转义的问题
- **1.6.3**: 原文内容长度限制改为严格截断（包含省略号在内）；避免正文过长导致同步失败
- **1.6.1**: 移除“同步时显示原文内容”选项，改为由模板 `{content}` 控制；修复部分场景下正文无法带入的问题
- **1.6.0**: 新增自定义同步内容模板功能，支持显示原文内容，优化后台管理界面
  - 添加是否显示原文内容的选项
  - 支持自定义同步内容模板，包含6个变量
  - 后台面板显示当前配置信息
  - 优化内容处理和格式化
- 1.5.6: 修复数据库的兼容性
- 1.5.5: 修改判断是否为新文章的逻辑，避免重复同步。
- 1.5.0：更新消息格式，移除摘要内容
- 1.4.0：添加Misskey支持
- 1.3.1：修复多项Bug，增强兼容性
- 1.3.0：添加API超时设置
- 1.2.0：添加GoToSocial支持
- 1.1.0：增加手动同步功能
- 1.0.0：初始版本，基本功能实现

## 兼容性

- Typecho 1.2 及以上版本
- PHP 7.0 及以上版本

## 许可证

本插件遵循MIT许可证发布。 
