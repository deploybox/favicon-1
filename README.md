# FetchFavicon

用 PHP 获取网站 favicon 的API，可用于美化网站外链显示效果。

## 部署

### 使用 [`Vercel`](https://github.com/vercel-community/php) 部署

<a href="https://vercel.com/new/clone?repository-url=https://github.com/deploybox/FetchFavicon&project-name=favicon&repository-name=favicon"><img src="https://vercel.com/button"></a>

### Nginx

将 `api` 目录设置为根目录，或者将 `index.php` 放置在网站根目录下即可。

**伪静态**，方便 CDN 缓存

```sh
# Nginx规则
rewrite ^/favicon/(.*)\.png$ /api/index.php?url=$1;
```

## 使用

`https://favicons-idev.vercel.app/?url=域名`

```
https://favicons-idev.vercel.app/?url=example.com
```

## 示例

- [x] 百度 ![](https://favicons-idev.vercel.app/?url=www.baidu.com)
- [x] 维基百科 ![](https://favicons-idev.vercel.app/?url=www.wikipedia.org)
- [x] segmentfault ![](https://favicons-idev.vercel.app/?url=segmentfault.com)
- [x] GitHub ![](https://favicons-idev.vercel.app/?url=github.com)

## 鸣谢

- https://github.com/owen0o0/getFavicon

## LICENSE

MIT
