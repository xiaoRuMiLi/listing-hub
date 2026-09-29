<?php

/**
 * ★ R4：商品类型（product_type / 亚马逊 PT）→ 该 PT「必填属性」清单。
 *
 * 用途：`POST /sync/push` 时若 `attrs_json` 为空、或缺该 PT 必填，
 *      在响应 `data.warnings[]` 里给出**不拒写**的告警（本地 `hub.js import` 会打印）。
 *
 * 口径（重要）：
 *  - 这里只放**有证据**的条目（来自本地实测的 `listing_diff.json#requiredMissing` / schema 导出）。
 *  - 拿不准的 PT 请**留空**（不猜原则），由 schema/字段字典定稿后补。
 *  - `_always` = 跨 PT 的通用必填（在本仓 schema 里对所有 PT 都出现）。
 */
return [

    // 跨 PT 通用必填（本地实测：必填但常空）
    // ★ R12（2026-09-30）：清空 —— `externally_assigned_product_identifier` / `merchant_suggested_asin`
    //   属于亚马逊侧「（该 listing 存在时）」条件项，本链路不主动填（GTIN 豁免在账号级配置），
    //   实测 10809(PILLOWCASE) / 12669(CARRIER_BAG_CASE) 不带这两项也 **VALID** → 避免误报。
    '_always' => [],

    // 按 PT 追加（示例，按需扩充；填「属性 key」，非中文标签）
    // ★ 比对前会做 key 归一化（剥 `[...]`/`#n`/`.…` 后缀），故这里写【裸属性名】即可。
    // ★ 已实测灌入（SP-API VALIDATION 报缺 → 补后 VALID）：
    'PILLOWCASE' => [
        'thread',              // Thread Count（schema: thread[].count#1.value，整数）
        'closure',             // Closure Type（枚举 Button/Envelope/Snap/Tie/Zipper）
        'unit_count',
        'item_length_width',   // Item Dimensions L x W（单位仅 centimeters）
        'pattern',             // 枚举 28 项（含 Solid）
        'number_of_items',
    ],
    'PET_APPAREL' => [
        'breed_recommendation',
        'gdpr_risk',
        'specific_uses_for_product',
        'warranty_description',
        'unit_count',
    ],
];
