<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
                xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
                xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
                xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
                exclude-result-prefixes="sitemap news image">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>

<xsl:template match="/">
    <html>
        <head>
            <title>News XML Sitemap</title>
            <style type="text/css">
                :root {
                    --brand-color: #48991E;
                    --background-color: #f9f9f9;
                    --content-background: #ffffff;
                    --text-color: #333333;
                    --border-color: #e5e7eb;
                    --header-background: #f8f9fa;
                    --hover-background: #f1f1f1;
                }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
                    background-color: var(--background-color);
                    color: var(--text-color);
                    margin: 0;
                    padding: 0;
                }
                #header {
                    padding: 1em 2em;
                    background: var(--content-background);
                    border-bottom: 1px solid var(--border-color);
                }
                #header h1 {
                    font-size: 24px;
                    font-weight: 600;
                    margin: 0;
                }
                #header p {
                    font-size: 14px;
                    margin: 0.5em 0 0;
                    color: #666;
                }
                #content {
                    max-width: 1400px;
                    margin: 2em auto;
                    padding: 0 2em;
                }
                a {
                    color: var(--brand-color);
                    text-decoration: none;
                }
                a:hover {
                    text-decoration: underline;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 2em;
                    font-size: 14px;
                    background: var(--content-background);
                    border: 1px solid var(--border-color);
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                }
                th, td {
                    text-align: left;
                    padding: 1em 1.25em;
                    border-bottom: 1px solid var(--border-color);
                }
                thead th {
                    background-color: var(--header-background);
                    font-weight: 600;
                    font-size: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                tbody tr:last-child td {
                    border-bottom: none;
                }
                tbody tr:hover {
                    background-color: var(--hover-background);
                }
                td {
                    vertical-align: middle;
                }
                .url-cell {
                    max-width: 300px;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                img {
                    max-width: 80px;
                    max-height: 40px;
                    border: 1px solid var(--border-color);
                    border-radius: 4px;
                    vertical-align: middle;
                }
                .empty-message {
                    margin-top: 2em;
                    padding: 1.5em;
                    background-color: var(--content-background);
                    border-left: 4px solid var(--brand-color);
                    border-radius: 4px;
                }
                @media screen and (max-width: 768px) {
                    #content {
                        padding: 0 1em;
                    }
                    table, thead, tbody, th, td, tr {
                        display: block;
                    }
                    thead tr {
                        position: absolute;
                        top: -9999px;
                        left: -9999px;
                    }
                    tr {
                        border: 1px solid var(--border-color);
                        border-radius: 8px;
                        margin-bottom: 1em;
                    }
                    td {
                        border: none;
                        border-bottom: 1px solid var(--border-color);
                        position: relative;
                        padding-left: 50%;
                        text-align: right;
                    }
                    td:before {
                        position: absolute;
                        top: 50%;
                        left: 1.25em;
                        width: 45%;
                        padding-right: 10px;
                        white-space: nowrap;
                        text-align: left;
                        font-weight: 600;
                        transform: translateY(-50%);
                    }
                    td:nth-of-type(1):before { content: "Image"; }
                    td:nth-of-type(2):before { content: "URL"; }
                    td:nth-of-type(3):before { content: "Title"; }
                    td:nth-of-type(4):before { content: "Keywords"; }
                    td:nth-of-type(5):before { content: "Genres"; }
                    td:nth-of-type(6):before { content: "Tickers"; }
                    td:nth-of-type(7):before { content: "Published"; }
                    td:nth-of-type(8):before { content: "Modified"; }
                    .url-cell {
                        max-width: none;
                        white-space: normal;
                        word-break: break-all;
                    }
                }
            </style>
        </head>
        <body>
            <div id="header">
                <h1>News XML Sitemap</h1>
                <p>Generated by <strong><a href="https://boardingarea.com/">BoardingArea</a></strong>. For more information, visit <a href="https://sitemaps.org">sitemaps.org</a>.</p>
            </div>
            <div id="content">
                <xsl:choose>
                    <xsl:when test="count(sitemap:urlset/sitemap:url) > 0">
                        <p>This News XML Sitemap contains <xsl:value-of select="count(sitemap:urlset/sitemap:url)"/> articles.</p>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 7%;">Image</th>
                                    <th style="width: 25%;">URL</th>
                                    <th style="width: 23%;">Article Title</th>
                                    <th style="width: 10%;">Keywords</th>
                                    <th style="width: 10%;">Genres</th>
                                    <th style="width: 10%;">Stock Tickers</th>
                                    <th style="width: 8%;">Published</th>
                                    <th style="width: 7%;">Modified</th>
                                </tr>
                            </thead>
                            <tbody>
                                <xsl:for-each select="sitemap:urlset/sitemap:url">
                                    <tr>
                                        <td>
                                            <xsl:if test="image:image/image:loc">
                                                <img src="{image:image/image:loc}" alt="Featured Image" />
                                            </xsl:if>
                                        </td>
                                        <td class="url-cell">
                                            <a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a>
                                        </td>
                                        <td>
                                            <xsl:value-of select="news:news/news:title"/>
                                        </td>
                                        <td>
                                            <xsl:value-of select="news:news/news:keywords"/>
                                        </td>
                                        <td>
                                            <xsl:value-of select="news:news/news:genres"/>
                                        </td>
                                        <td>
                                            <xsl:value-of select="news:news/news:stock_tickers"/>
                                        </td>
                                        <td>
                                            <xsl:value-of select="substring-before(news:news/news:publication_date, 'T')"/>
                                        </td>
                                        <td>
                                            <xsl:value-of select="substring-before(sitemap:lastmod, 'T')"/>
                                        </td>
                                    </tr>
                                </xsl:for-each>
                            </tbody>
                        </table>
                    </xsl:when>
                    <xsl:otherwise>
                        <p class="empty-message">No articles have been published in the last 48 hours.</p>
                    </xsl:otherwise>
                </xsl:choose>
            </div>
        </body>
    </html>
</xsl:template>
</xsl:stylesheet>
