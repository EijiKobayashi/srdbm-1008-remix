# SRDBM 1008 REMIX

**（仮）Search-Replace-DB-master 1008 REMIX**（略称: **SRDBM 1008 REMIX**）は、Webサイトのリニューアル公開に伴うデータベース置換作業を、安全かつ効率的に行うためのツールです。

## 概要

ローカル環境にダンプしたSQLデータを対象に、[Search Replace DB](https://github.com/interconnectit/Search-Replace-DB) の `srdb.cli.php` を利用して置換処理を行います。

Search Replace DBの最新版を基盤とし、置換前のバックアップや接続先に応じた処理など、公開作業で必要となる一連の操作を支援することを目指します。

## 前提

- データベースのSQLダンプをローカル環境に用意していること
- MySQLまたはMariaDBを利用できること
- Search Replace DBの `srdb.cli.php` を利用できること

## 実装予定

### 必須機能

- 置換元と置換後の情報を設定する
- 置換前にデータベースをバックアップする
- MySQLとMariaDBの両方に対応する
- データベース内のURLを `http` から `https` へ置換する

### 任意機能

- データベース内の管理者メールアドレスを表示し、変更できるようにする
- データベース内の画像パスを表示し、変更できるようにする
- データベース内のプラグイン情報を一覧表示し、有効・無効を設定できるようにする

## ステータス

現在は構想・初期開発段階です。

## 謝辞

本プロジェクトは [interconnectit/Search-Replace-DB](https://github.com/interconnectit/Search-Replace-DB) を利用する予定です。利用時は同プロジェクトのライセンスおよび注意事項に従います。
