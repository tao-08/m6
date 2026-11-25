// @ffmpeg/ffmpeg 0.12.15 (MIT, https://github.com/ffmpegwasm/ffmpeg.wasm) の dist/esm をそのまま置いたもの。
// Worker は同じオリジンからしか作れないので、本体（ffmpeg-core）だけ CDN から読む（assets/setlist_detect.js）
export * from "./classes.js";
export * from "./types.js";
