import asyncio
import sys

import uvicorn


def configure_windows_event_loop() -> None:
    if sys.platform != "win32":
        return

    asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())


if __name__ == "__main__":
    configure_windows_event_loop()
    uvicorn.run(
        "app.main:app",
        host="0.0.0.0",
        port=8001,
        ssl_keyfile="C:/laragon/etc/ssl/laragon.key",
        ssl_certfile="C:/laragon/etc/ssl/laragon.crt",
    )
