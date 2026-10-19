{{-- Screens shared by both actors extend this name: it renders the shell of the signed-in user's role
     (ADMIN: layouts.admin, TESTER: layouts.tester), see App\Support\RoleLayout (S14-04). --}}
@extends(\App\Support\RoleLayout::for(auth()->user()))
