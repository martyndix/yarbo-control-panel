#pragma once

#define PAPERMONO_FW_VERSION "0.1.2-beta"
/* Status poll. Do not go much faster: SSD1677 must not stream partial refreshes. */
#define PAPERMONO_POLL_MS 15000
#define PAPERMONO_PAGE_HOME 0
#define PAPERMONO_PAGE_STATUS 1
#define PAPERMONO_PAGE_HEALTH 2
#define PAPERMONO_PAGE_PLANS 3
#define PAPERMONO_PAGE_COUNT 4
#define PAPERMONO_PLAN_MAX 20
#define PAPERMONO_PLAN_VISIBLE 6
