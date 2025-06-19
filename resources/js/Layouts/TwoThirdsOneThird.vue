<template>
    <div class="flex flex-wrap md:flex-nowrap">
        <div class="w-100 lg:w-2/3 mt-5 lg:mt-0">
            <slot name="main"></slot>
        </div>
        <div class="w-100 lg:w-1/3 lg:ml-10 mt-5 lg:mt-0">
            <slot name="sidebar"></slot>
        </div>
    </div>
</template>