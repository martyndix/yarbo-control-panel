#pragma once

#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>

/* Let's Encrypt ISRG Root X1 — Tailscale Funnel and Cloudflare use this chain. */
static const char PAPER_ISRG_ROOT_X1[] = R"EOF(
-----BEGIN CERTIFICATE-----
MIIFazCCA1OgAwIBAgIRAIIQz7DSQONZRGPgu2OCiwAwDQYJKoZIhvcNAQELBQAw
TzELMAkGA1UEBhMCVVMxKTAnBgNVBAoTIEludGVybmV0IFNlY3VyaXR5IFJlc2Vh
cmNoIEdyb3VwMRUwEwYDVQQDEwxJU1JHIFJvb3QgWDEwHhcNMTUwNjA0MTEwNDM4
WhcNMzUwNjA0MTEwNDM4WjBPMQswCQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJu
ZXQgU2VjdXJpdHkgUmVzZWFyY2ggR3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBY
MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAK3oJHP0FDfzm54rVygc
h77ct984kIxuPOZXoHj3dcKi/vVqbvYATyjb3miGbESTtrFj/RQSa78f0uoxmyF+
0TM8ukj13Xnfs7j/EvEhmkvBioZxaUpmZmyPfjxwv60pIgbz5MDmgK7iS4+3mX6U
A5/TR5d8mUgjU+g4rk8Kb4Mu0UlXjIB0ttov0DiNewNwIRt18jA8+o+u3dpjq+sW
T8KOEUt+zwvo/7V3LvSye0rgTBIlDHCNAymg4VMk7BPZ7hm/ELNKjD+Jo2FR3qyH
B5T0Y3HsLuJvW5iB4YlcNHlsdu87kGJ55tukmi8mxdAQ4Q7e2RCOFvu396j3x+UC
B5iPNgiV5+I3lg02dZ77DnKxHZu8A/lJBdiB3QW0KtZB6awBdpUKD9jf1b0SHzUv
KBds0pjBqAlkd25HN7rOrFleaJ1/ctaJxQZBKT5ZPt0m9STJEadao0xAH0ahmbWn
OlFuhjuefXKnEgV4We0+UXgVCwOPjdAvBbI+e0ocS3MFEvzG6uBQE3xDk3SzynTn
jh8BCNAw1FtxNrQHusEwMFxIt4I7mKZ9YIqioymCzLq9gwQbooMDQaHWBfEbwrbw
qHyGO0aoSCqI3Haadr8faqU9GY/rOPNk3sgrDQoo//fb4hVC1CLQJ13hef4Y53CI
rU7m2Ys6xt0nUW7/vGT1M0NPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV
HRMBAf8EBTADAQH/MB0GA1UdDgQWBBR5tFnme7bl5AFzgAiIyBpY9umbbjANBgkq
hkiG9w0BAQsFAAOCAgEAVR9YqbyyqFDQDLHYGmkgJykIrGF1XIpu+ILlaS/V9lZL
ubhzEFnTIZd+50xx+7LSYK05qAvqFyFWhfFQDlnrzuBZ6brJFe+GnY+EgPbk6ZGQ
3BebYhtF8GaV0nxvwuo77x/Py9auJ/GpsMiu/X1+mvoiBOv/2X/qkSsisRcOj/KK
NFtY2PwByVS5uCbMiogziUwthDyC3+6WVwW6LLv3xLfHTjuCvjHIInNzktHCgKQ5
ORAzI4JMPJ+GslWYHb4phowim57iaztXOoJwTdwJx4nLCgdNbOhdjsnvzqvHu7Ur
TkXWStAmzOVyyghqpZXjFaH3pO3JLF+l+/+sKAIuvtd7u+Nxe5AW0wdeRlN8NwdC
0jNPElpzVmbUq4JUagEiuTDkHzsxHpFKVK7q4+63SM1N95R1NbdWhscdCb+ZAJzVc
oyi3B43njTOQ5yOf+1CceWxG1bQVs5ZufpsMljq4Ui0/1lvh+wjChP4kqKOJ2qxq
4RgqsahDYVvTH9w7jXbyLeiNdd8XM2w9U/t7y0Ff/9yi0GE44Za4rF2LN9d11TPA
mRGunUHBcnWEvgJBQl9nJEiU0Zsnvgc/ubhPgXRR4Xq37Z0j4r7g1SgEEzwxA57d
emyPxgcYxn/eR44/KJ4EBs+lVDR3veyJm+kXQ99b21/+jh5Xos1AnX5iItreGCc=
-----END CERTIFICATE-----
)EOF";

extern String panelUrl;
extern String remoteUrl;
extern String token;
extern String wifiSsid;
extern bool usingRemote;

static WiFiClient paperNetPlain;
static WiFiClientSecure paperNetSecure;

inline bool paperNetIsHttps(const String &url)
{
    return url.startsWith("https://") || url.startsWith("HTTPS://");
}

inline void paperNetNormalize(String &url)
{
    url.replace(" ", "");
    while (url.endsWith("/")) {
        url.remove(url.length() - 1);
    }
}

/* Home LAN IP is unreachable on travel Wi-Fi. ESP32 connect() to that subnet
 * can hang far past HTTPClient's timeout, so Funnel never runs (no R). */
inline bool paperNetLanReachable()
{
    if (!panelUrl.length()) {
        return false;
    }
    if (!remoteUrl.length() || remoteUrl == panelUrl) {
        return true;
    }
    if (WiFi.status() != WL_CONNECTED) {
        return false;
    }
    if (wifiSsid.length() && WiFi.SSID() != wifiSsid) {
        return false;
    }
    return true;
}

inline bool paperNetBeginBase(HTTPClient &http, const String &base, const String &pathQuery, int timeoutMs)
{
    if (!base.length()) {
        return false;
    }
    String url = base + pathQuery;
    http.setTimeout(timeoutMs);
    http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    if (paperNetIsHttps(base)) {
        paperNetSecure.setCACert(PAPER_ISRG_ROOT_X1);
        paperNetSecure.setTimeout(timeoutMs);
        return http.begin(paperNetSecure, url);
    }
    paperNetPlain.setTimeout(timeoutMs);
    return http.begin(paperNetPlain, url);
}

inline int paperNetGet(HTTPClient &http, const String &pathQuery, int remoteMs = 8000)
{
    usingRemote = false;
    int lanMs = remoteUrl.length() ? 2000 : remoteMs;
    if (paperNetLanReachable() && paperNetBeginBase(http, panelUrl, pathQuery, lanMs)) {
        http.addHeader("X-PaperMono-Token", token);
        int code = http.GET();
        if (code == 200) {
            return code;
        }
        http.end();
        paperNetPlain.stop();
    }
    if (remoteUrl.length() && remoteUrl != panelUrl) {
        if (paperNetBeginBase(http, remoteUrl, pathQuery, remoteMs)) {
            http.addHeader("X-PaperMono-Token", token);
            int code = http.GET();
            if (code == 200) {
                usingRemote = true;
            }
            return code;
        }
    }
    return -1;
}

inline int paperNetPost(HTTPClient &http, const String &pathQuery, const String &payload, int remoteMs = 8000)
{
    usingRemote = false;
    int lanMs = remoteUrl.length() ? 2000 : remoteMs;
    if (paperNetLanReachable() && paperNetBeginBase(http, panelUrl, pathQuery, lanMs)) {
        http.addHeader("Content-Type", "application/json");
        http.addHeader("X-PaperMono-Token", token);
        int code = http.POST(payload);
        if (code == 200) {
            return code;
        }
        http.end();
        paperNetPlain.stop();
    }
    if (remoteUrl.length() && remoteUrl != panelUrl) {
        if (paperNetBeginBase(http, remoteUrl, pathQuery, remoteMs)) {
            http.addHeader("Content-Type", "application/json");
            http.addHeader("X-PaperMono-Token", token);
            int code = http.POST(payload);
            if (code == 200) {
                usingRemote = true;
            }
            return code;
        }
    }
    return -1;
}

inline bool paperNetOta(const String &pathQuery)
{
    HTTPUpdate updater(300000);
    updater.rebootOnUpdate(true);
    updater.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    t_httpUpdate_return ret = HTTP_UPDATE_FAILED;
    if (paperNetLanReachable()) {
        String url = panelUrl + pathQuery;
        if (paperNetIsHttps(panelUrl)) {
            paperNetSecure.setCACert(PAPER_ISRG_ROOT_X1);
            ret = updater.update(paperNetSecure, url);
        } else {
            ret = updater.update(paperNetPlain, url);
        }
        if (ret == HTTP_UPDATE_OK) {
            usingRemote = false;
            return true;
        }
        paperNetPlain.stop();
    }
    if (remoteUrl.length() && remoteUrl != panelUrl) {
        String url = remoteUrl + pathQuery;
        paperNetSecure.setCACert(PAPER_ISRG_ROOT_X1);
        ret = updater.update(paperNetSecure, url);
        if (ret == HTTP_UPDATE_OK) {
            usingRemote = true;
            return true;
        }
    }
    return false;
}
