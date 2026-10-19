<?php

namespace App\Support;

use App\Models\User;

/**
 * Tailwind colour classes of the shell accent (S14-06): primary buttons and active tabs on screens
 * shared by both roles follow the shell they are rendered in (indigo: ADMIN, teal: TESTER,
 * RoleLayout::accent), instead of a hard-coded indigo. Only colour classes live here, so each
 * screen keeps its own size and spacing. The class strings are literal so that Tailwind finds them
 * (resources/css/app.css lists this file as a @source).
 */
final class AccentClasses
{
    /** Filled primary button (create, save, approve, start). */
    public static function primaryButton(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'bg-teal-700 text-white shadow-teal-700/25 hover:bg-teal-600 focus-visible:ring-teal-300'
            : 'bg-indigo-600 text-white shadow-indigo-600/25 hover:bg-indigo-500 focus-visible:ring-indigo-300';
    }

    /**
     * Page-header create button ("Tạo dự án mới", "Thêm quy tắc mới"…): one style on every screen,
     * no wrap and not stretched at 375 px (S14-04 review), in the shell accent.
     */
    public static function headerCta(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'inline-flex shrink-0 items-center justify-center gap-2 self-start whitespace-nowrap rounded-xl bg-teal-700 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-teal-700/25 transition hover:bg-teal-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 sm:self-auto'
            : 'inline-flex shrink-0 items-center justify-center gap-2 self-start whitespace-nowrap rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:bg-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 sm:self-auto';
    }

    /** Selected tab of a role="tablist" (underlined tabs). */
    public static function activeTab(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'border-teal-400 text-teal-300 font-semibold bg-teal-500/10'
            : 'border-indigo-500 text-indigo-400 font-semibold bg-indigo-500/10';
    }

    /** Selected option of a segmented control (file / text, method filter). */
    public static function activeSegment(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'bg-teal-700 text-white shadow-md'
            : 'bg-indigo-600 text-white shadow-md';
    }

    /**
     * Tinted secondary action ("Sửa", "Xem", "Chi tiết", "Xem báo cáo", bulk approve): border,
     * background and text colours only (S14-06 live QA r3).
     */
    public static function secondaryButton(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'border-teal-500/30 bg-teal-500/10 text-teal-300 hover:bg-teal-500/20'
            : 'border-indigo-500/30 bg-indigo-500/10 text-indigo-300 hover:bg-indigo-500/20';
    }

    /** Text link inside content ("Tiến trình sinh #N", "Xem kết quả →", "#12"). */
    public static function link(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'text-teal-300 hover:text-teal-200'
            : 'text-indigo-400 hover:text-indigo-300';
    }

    /** Selected card of a list (M07 endpoint card). */
    public static function selectedCard(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'bg-teal-600/15 border-teal-500/50 shadow-md ring-1 ring-teal-500/30'
            : 'bg-indigo-600/15 border-indigo-500/50 shadow-md ring-1 ring-indigo-500/30';
    }

    /** Ring of a pressed toggle card (M12 verdict counters used as filters). */
    public static function pressedRing(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'ring-2 ring-teal-400'
            : 'ring-2 ring-indigo-400';
    }

    /** Checked colour of a checkbox. */
    public static function checkbox(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'text-teal-600 accent-teal-600' : 'text-indigo-500 accent-indigo-500';
    }

    /** Small text marker in the accent (selected dot, "N đã chọn"). */
    public static function accentText(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'text-teal-300' : 'text-indigo-400';
    }

    /** Focus ring of an input, select or textarea (`focus:ring-2` stays on the element). */
    public static function focusRing(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'focus:ring-teal-500' : 'focus:ring-indigo-500';
    }

    /** Focus border of an input, select or textarea. */
    public static function focusBorder(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'focus:border-teal-500' : 'focus:border-indigo-500';
    }

    /** Keyboard focus ring of a scrollable region, tab or `<pre tabindex="0">` (`focus-visible:ring-2` stays on the element). */
    public static function focusVisibleRing(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'focus-visible:ring-teal-500' : 'focus-visible:ring-indigo-500';
    }

    /** Current page of the pagination (S14-06 live QA r4). */
    public static function currentPage(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'bg-teal-700 border-teal-500 text-white'
            : 'bg-indigo-600 border-indigo-500 text-white';
    }

    /**
     * Accent label text: breadcrumb current item, step title (M10), HTTP method of the M13 endpoint
     * table, "Request" heading (M12).
     */
    public static function label(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'text-teal-300' : 'text-indigo-300';
    }

    /** Hover colour of a breadcrumb link. */
    public static function breadcrumbLink(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'hover:text-teal-300' : 'hover:text-indigo-400';
    }

    /** Tinted code badge (M08 rule code "BR-SS-001"). */
    public static function codeBadge(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'bg-teal-500/10 text-teal-300 border-teal-500/30'
            : 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30';
    }

    /** Icon tile next to a page title (M06, M07, M08 headers). */
    public static function iconTile(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'bg-teal-500/10 border-teal-500/20 text-teal-300'
            : 'bg-indigo-500/10 border-indigo-500/20 text-indigo-400';
    }

    /** Border and background of an accent summary card ("Tỉ lệ đạt" on M13, "Đã duyệt" on M09). */
    public static function statCard(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'border-teal-500/30 bg-teal-950/20'
            : 'border-indigo-500/30 bg-indigo-950/20';
    }

    /** Caption of that summary card. */
    public static function statCardLabel(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'text-teal-300' : 'text-indigo-400';
    }

    /** Value of that summary card. */
    public static function statCardValue(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'text-teal-200' : 'text-indigo-300';
    }

    /** Small filled dot (M06 version bullet, M07 endpoint count pill) (S14-06 live QA r7). */
    public static function dot(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'bg-teal-400' : 'bg-indigo-400';
    }

    /** Border and background of the M06 dropzone while a file is dragged over it. */
    public static function dropzoneActive(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal'
            ? 'border-teal-500 bg-teal-500/10'
            : 'border-indigo-500 bg-indigo-500/10';
    }

    /** Hover border of an outlined secondary link (M13/M15 export buttons). */
    public static function hoverBorder(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'hover:border-teal-400' : 'hover:border-indigo-400';
    }

    /** Text selection colour of the application shell (`selection:text-white` stays on the body). */
    public static function selection(?User $user): string
    {
        return RoleLayout::accent($user) === 'teal' ? 'selection:bg-teal-600' : 'selection:bg-indigo-500';
    }
}
