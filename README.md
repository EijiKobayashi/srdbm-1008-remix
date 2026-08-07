# SRDBM 1008 REMIX

Current version: **v0.0.4**

**Search-Replace-DB-master 1008 REMIX** は、WordPressなどのMySQL/MariaDBダンプをデータベースへ接続せず、SQLファイルのまま安全にURL置換するツールです。

## 主な機能

- SQLファイルを直接入力・出力（DB接続不要）
- PHPシリアライズデータの文字数を自動補正
- 置換元URL・置換後URLの指定
- HTTPからHTTPSへの補正
- WordPressテーブル接頭辞の変更（任意）
- 管理者メールアドレスの検出・変更（任意）
- WordPress画像パスの検出・変更（任意）
- SQL内のプラグイン情報の一覧・有効／無効切り替え（任意）
- 元SQLの自動バックアップ
- 変更件数だけ確認するドライラン
- 500MBまでの分割アップロード
- Web UIとCLIの両方に対応

## ディレクトリ

```text
bin/          CLI
config/       任意の初期設定
public/       Web UI
src/          SQL変換エンジン
sql/input/    入力SQL
sql/backups/  元SQLのバックアップ
sql/output/   変換済みSQL
```

## Web UI

### Localで使用する

Localサイトの `app/public/` 内へ本リポジトリ一式を配置し、次のようなURLを開きます。

```text
https://サイト名.local/srdbm-1008-remix/
```

SRDBM自体はBasic認証やWordPressログインを要求しません。設置先サーバーにBasic認証がある場合は、その認証がそのまま適用されます。

### 通常のWebサーバーで使用する

PHP 7.4以上が動作するApacheサーバーへリポジトリ一式をアップロードし、設置URLを開きます。`.htaccess` により、UIと静的ファイル以外へのWebアクセスは拒否されます。

Nginxの場合はドキュメントルートを `public/` に設定し、`sql/` へPHPプロセスの書き込み権限を付与してください。

## 使い方

1. `.sql` ファイルを選択または画面へドロップします。
2. 「アップロードする」を実行し、アップロード完了を確認します。
3. 置換元URLと置換後URLを入力します。
4. 必要な場合だけ変更前・変更後のテーブル接頭辞を入力します。
5. 必要な場合だけ、検出された管理者メール・画像パス・プラグイン設定を変更します。
6. 「ドライランしてバックアップ」を実行します。
7. 変更件数と作成済みバックアップを確認します。
8. 「変換する」を実行します。
9. 完了後、変換済みSQLをダウンロードします。

アップロードは約768KBずつに分割されるため、合計500MBまで扱えます。PHP側にも `public/.user.ini` で500MBの上限を設定しています。

プラグイン一覧は、SQL内の `active_plugins`、`sitewide_plugins`、`recently_activated` などで確認できるプラグインパスを対象にします。SQLにも記録がない未導入・未使用プラグインは表示できません。

## 初期値の保存（任意）

```bash
cp config/srdbm.example.php config/srdbm.php
```

`config/srdbm.php` の `source_url` と `destination_url` が画面の初期値になります。DB接続情報はありません。

## CLI

ドライラン:

```bash
php bin/srdbm.php \
  --input=sql/input/database.sql \
  --search=http://old.example.com \
  --replace=https://new.example.com \
  --dry-run
```

変換済みSQLを出力:

```bash
php bin/srdbm.php \
  --input=sql/input/database.sql \
  --search=http://old.example.com \
  --replace=https://new.example.com
```

## 注意

- 本番利用前に必ずドライランと復元テストを行ってください。
- 500MBの入力では、元ファイル、バックアップ、出力ファイルのため十分な空き容量が必要です。
- 本ツールはSQL文字列リテラルを対象に置換します。独自形式のダンプでは事前テストを推奨します。

## 謝辞

シリアライズデータを考慮した置換思想は [interconnectit/Search-Replace-DB](https://github.com/interconnectit/Search-Replace-DB) を参考にしています。
