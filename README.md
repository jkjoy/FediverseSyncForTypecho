# Fediverse Sync for Typecho

这是一个Typecho插件，可以将你的Typecho博客文章自动同步发布到Fediverse网络中的各个实例，包括Mastodon、GoToSocial和Misskey。

## 功能特点

- 发布新文章时自动同步到Fediverse实例
- 支持多种Fediverse实例：
  - Mastodon
  - GoToSocial
  - Misskey
- 简洁的消息格式
- 支持多种可见性设置
- 调试模式支持
- 手动从后台面板同步文章

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

启用插件并配置好后，当你发布新文章时，插件会自动将文章同步到你配置的Fediverse实例，消息格式为：

```
「文章标题」
文章链接
From「网站名称」 
```

### 手动同步

进入Typecho后台 > Fediverse同步面板，你可以：
1. 查看所有文章的同步状态
2. 选择一篇或多篇文章手动同步

## 版本历史

- 1.5.0：更新消息格式，移除摘要内容
- 1.4.0：添加Misskey支持
- 1.3.1：修复多项Bug，增强兼容性
- 1.3.0：添加API超时设置
- 1.2.0：添加GoToSocial支持
- 1.1.0：增加手动同步功能
- 1.0.0：初始版本，基本功能实现

## 兼容性

- Typecho 1.1 及以上版本
- PHP 7.0 及以上版本

## 许可证

本插件遵循MIT许可证发布。 