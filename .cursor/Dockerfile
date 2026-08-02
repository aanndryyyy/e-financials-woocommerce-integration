FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV LANG=C.UTF-8

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    wget \
    gnupg \
    git \
    unzip \
    zip \
    jq \
    sudo \
    software-properties-common \
    apt-transport-https \
    build-essential \
    openssh-server \
    oathtool \
    && rm -rf /var/lib/apt/lists/*

# PHP (plugin requires >= 8.0; match CI on a modern 8.x)
RUN apt-get update && apt-get install -y --no-install-recommends \
    php8.3-cli \
    php8.3-common \
    php8.3-curl \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-zip \
    php8.3-mysql \
    php8.3-intl \
    && rm -rf /var/lib/apt/lists/*

# Composer
RUN curl --retry 3 --retry-delay 5 -fsSL https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version

# Node.js 24 (required by @woocommerce/e2e-utils-playwright)
RUN curl --retry 3 --retry-delay 5 -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm --version \
    && node --version \
    && rm -rf /var/lib/apt/lists/*

########################################################
# DOCKER (required for @wordpress/env / wp-env e2e)
########################################################

RUN install -m 0755 -d /etc/apt/keyrings && \
    curl --retry 3 --retry-delay 5 -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg && \
    chmod a+r /etc/apt/keyrings/docker.gpg && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
    $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    apt-get update && \
    apt-get install -y \
    docker-ce=5:28.5.2-1~ubuntu.24.04~noble \
    docker-ce-cli=5:28.5.2-1~ubuntu.24.04~noble \
    containerd.io \
    docker-buildx-plugin \
    docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

RUN apt-get update && apt-get install -y fuse-overlayfs iptables && rm -rf /var/lib/apt/lists/*
RUN mkdir -p /etc/docker && \
    printf '%s\n' '{' \
    '  "storage-driver": "fuse-overlayfs"' \
    '}' > /etc/docker/daemon.json
RUN update-alternatives --set iptables /usr/sbin/iptables-legacy && \
    update-alternatives --set ip6tables /usr/sbin/ip6tables-legacy

########################################################
# CONFIG UBUNTU USER
########################################################

RUN mkdir -p /etc/ssh/sshd_config.d && \
    echo 'PasswordAuthentication no\nChallengeResponseAuthentication no\nUsePAM no' > /etc/ssh/sshd_config.d/disable_password_auth.conf

RUN id -u ubuntu &>/dev/null || useradd -m -s /bin/bash ubuntu
RUN groupadd -f docker && usermod -aG docker ubuntu
RUN usermod -aG sudo ubuntu
RUN echo "ubuntu ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/ubuntu
RUN echo "ubuntu:ubuntu" | chpasswd
