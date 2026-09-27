# Merge bootloader + partitions + app into firmware-factory.bin (USB flash at 0x0).
# PlatformIO's firmware.bin is the application only; writing that at 0x0 overwrites the bootloader.

Import("env")

def merge_factory(source, target, env):
    import shutil
    import subprocess
    from pathlib import Path

    build = Path(env.subst("$BUILD_DIR"))
    app = Path(str(target[0]))
    bootloader = build / "bootloader.bin"
    partitions = build / "partitions.bin"
    factory = build / "firmware-factory.bin"
    platform = env.PioPlatform()
    framework = Path(platform.get_package_dir("framework-arduinoespressif32"))
    boot_app0_src = framework / "tools" / "partitions" / "boot_app0.bin"
    boot_app0 = build / "boot_app0.bin"
    if not boot_app0_src.is_file():
        raise Exception("boot_app0.bin is missing from the Arduino-ESP32 package.")
    shutil.copy(boot_app0_src, boot_app0)
    for path in (bootloader, partitions, app, boot_app0):
        if not path.is_file() or path.stat().st_size < 32:
            raise Exception(f"{path.name} is missing after the firmware build.")
    python = env.subst("$PYTHONEXE")
    args = [
        "-o",
        str(factory),
        "--flash_mode",
        "dio",
        "--flash_freq",
        "80m",
        "--flash_size",
        "16MB",
        "0x0",
        str(bootloader),
        "0x8000",
        str(partitions),
        "0xe000",
        str(boot_app0),
        "0x10000",
        str(app),
    ]
    print("Merging USB factory image (bootloader + partitions + app) …")
    last = None
    for sub in ("merge_bin", "merge-bin"):
        cmd = [python, "-m", "esptool", "--chip", "esp32s3", sub, *args]
        proc = subprocess.run(cmd, check=False)
        if proc.returncode == 0:
            last = None
            break
        last = proc.returncode
    if last is not None:
        raise Exception("esptool merge_bin failed (exit %s)." % last)
    if not factory.is_file() or factory.stat().st_size < 1024:
        raise Exception("firmware-factory.bin was not created.")

env.AddPostAction("$BUILD_DIR/${PROGNAME}.bin", merge_factory)
