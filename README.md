# FediverseSyncForTypecho
一个同步博客文章到联邦宇宙,并获取对应嘟文回复作为文章的评论的Typecho插件

## 数据库支持

本插件支持以下数据库：
- MySQL 5.7+
- MariaDB 10.2+
- SQLite 3.x

数据库用户需要具有以下权限：
- CREATE TABLE
- CREATE INDEX (SQLite)
- INSERT
- UPDATE
- SELECT
- DELETE（如果需要删除功能）